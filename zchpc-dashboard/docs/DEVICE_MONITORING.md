# Device monitoring, refresh and recovery

Implemented 29 October 2026 for the ZCHPC Access Control Dashboard (Laravel 12, Hikvision ISAPI terminals).

Every change is additive. Existing terminals, personnel, access groups, attendance records, access events, door controls and login/permission behaviour are preserved: the migration only adds tables and defaulted columns, no existing access-control operation was modified, and no device permission, schedule or credential was changed by any new action.

## 1. What the system already did (analysis before this change)

| Area | State before |
| --- | --- |
| Device registry | `terminals` table: name, phase, location, IP, port, username, encrypted password, protocol, timeout, verify_ssl, integration (`hikvision_isapi` or `standalone_wiegand`), model. Capabilities are derived from the integration type. |
| Device protocol | Local path package `../hikvision-isapi-master`: `DeviceManager` builds one `HikvisionClient` per device through the Eloquent-backed `EloquentDeviceProvider` (that provider exists so the encrypted password cast is applied). `DeviceService::getInfo()` reads `/ISAPI/System/deviceInfo`. Failures are classified as `DeviceUnreachableException`, `AuthenticationException`, `DeviceBusyException` or `HikvisionException` (with HTTP status). The package has no documented reboot call. |
| Online detection | `terminals:ping`, scheduled every minute, wrote `terminal.{id}.online` and `terminal.{id}.info` to the cache for 90 seconds. The devices page and the terminals list read those cache flags; nothing was stored, timestamped or explained. A device that had never been checked looked identical to one that was offline. |
| Dashboard metrics | The same command cached total personnel, today's event count and recent events taken from the first online terminal. |
| Manual checks | None. The only way to see a fresh status was to wait for the next scheduled ping. |
| Diagnostics | None. Failures were swallowed by the command; the UI showed "Cannot reach terminal". |
| Alerts | None. |
| Audit | No device-management history. |
| Roles | None. Every authenticated account had full access; the project review flagged this as an open item. |
| UI | Server-rendered Blade, one page per module, inline styles plus `public/css/app.css`, small amounts of inline JavaScript, no front-end build step. |

## 2. What was added

### A. Persisted device status with real checks

New `terminals` columns: `status`, `status_source`, `last_checked_at`, `last_seen_at`, `last_error`, `last_error_at`, `last_latency_ms`, `consecutive_failures`, `last_reconnect_at`. They are written only by `DeviceHealthService::check()`.

How a status is decided:

1. **TCP reachability** to `ip:port` (`NetworkReachability`). This is used only to fail unreachable devices quickly and to explain a network problem separately from a credential problem. **A reachable port never marks a device online.**
2. **Authenticated ISAPI read** (`/ISAPI/System/deviceInfo`) through the same client the rest of the application uses. Success → `online`, `last_seen_at = now`, latency recorded, error cleared, failure counter reset.
3. No connection → `offline` with the reason (timeout/refused) and `consecutive_failures + 1`. Authentication rejection → `error` (category `auth`). Busy/5xx → `error` (category `busy`). Other HTTP/protocol failures → `error`. A non-device exception (for example a saved-configuration problem) → `error` (category `internal`).
4. `connecting` is only shown while a queued bulk run is checking that device. Nothing is ever reported online from a cached flag, a previous success or an open socket.

Standalone/Wiegand readers are labelled **No remote API**: they are excluded from the online/offline/error counts and never treated as failures.

### B. Device management dashboard (`/devices`)

Live statistics: registered devices, online, offline, connecting, with errors, requiring attention (offline beyond the failure threshold, errored, stale, or never checked). Filters: All / Online / Offline / Connecting / Error / Attention, plus text search (name, phase, location, IP, model) and a phase filter. Each row shows name, ID, IP:port, integration and model, status badge, last checked, last successful communication, last response time, the current error or attention reason, and its management actions. The page also states that readers without a remote API are excluded from online counts.

The table is updated without a full page reload: every 20 seconds the page polls `GET /devices/statuses`, and active refresh runs are polled every 1.5 seconds.

### C. Manual refresh

* **Individual** — `POST /devices/{terminal}/refresh`. Runs a real check for that device only, stores the outcome and timestamps, returns the new row and statistics, and shows a useful diagnostic message when the check fails. Other devices are not contacted (asserted by test).
* **Refresh all** — `POST /devices/refresh` with `scope=all`. Creates a refresh run, snapshots the device list, and queues one job.
* **Refresh offline** — scoped to devices currently marked `offline`.
* **Refresh selected** — checkbox selection with a bulk button; the selected IDs are validated on the backend (`exists:terminals,id`, max 250) and only remote-managed devices are snapshotted.
* **Progress and cancellation** — the dashboard shows completed / pending / failed / cancelled counts and a progress bar, then the run summary ("Checked 4 of 4 device(s): 2 online, 1 offline, 1 with errors"). *Cancel pending checks* marks the run cancelled; the worker stops before contacting further devices. Only one run can be active: a second request returns the running run with an explanation instead of queueing duplicate work.

Bounded concurrency: each group of at most `devices.refresh_concurrency` (default 4) devices is probed concurrently at the TCP level by a non-blocking socket pool; the authenticated read is then performed one device at a time with a short spacing delay, so no terminal receives simultaneous configuration reads. At the application level one run is active at a time; how many runs the queue processes depends on how many workers you run.

### D. Automatic monitoring

`terminals:ping` remains the scheduled command (every minute) and now:

* respects the pause switch and the configured interval (a device is contacted again only after its interval has elapsed);
* uses `DeviceHealthService` for each due device, so status, timestamps and alerts persist;
* retries only transient failures (unreachable, busy) with exponential backoff — 2 attempts when scheduled, 1 when run with `--force`;
* keeps refreshing the dashboard metrics cache from the first terminal that is currently `online`, and a metrics failure can no longer change a device's connectivity status;
* supports `--force` (check everything now, used after adding or editing a terminal) and `--device=<name>`.

Configurable from the dashboard (administrators only): monitoring on/off, check interval (15–3600 seconds; presets 30 s, 1 min, 5 min, 10 min or custom) and alert cooldown.

### E. Diagnostics

`GET /devices/{terminal}/diagnostics` returns, per device: network reachability and latency, protocol read result, authentication result, last response time, last successful connection, consecutive failed attempts, last error, gateway/controller status, access-log synchronization status, recent failed operations, device-reported information, the device's reported clock (reported only — drift is not calculated), and troubleshooting recommendations. It is read-only: it never changes device configuration or the stored status, and it returns no password, token or credential field.

Gateway status is reported as **not applicable**: this application connects directly to each device's ISAPI port, and no separate gateway or controller entity is configured, so there is nothing to poll and nothing is invented.

### F. Reconnection, kept distinct from refresh and restart

`POST /devices/{terminal}/reconnect` (administrators) clears the cached device client and the provider's in-memory credentials, then authenticates again from scratch, with retries, and records the outcome. The UI requires confirmation, and states that the device is not restarted and no permissions change.

**Remote restart is deliberately not offered.** The installed package exposes no documented, authenticated reboot operation, and rebooting an access-control terminal could interrupt entry and exit while it boots. Refresh, reconnect and restart are therefore three different things, and only the first two exist. No new action unlocks, locks, or changes permissions, and nothing restarts devices in bulk.

### G. Alerts

In-app alert centre (`/devices/alerts`, sidebar badge and header bell show the unacknowledged count) with types: device offline, device restored, connection authentication failure, communication failure, repeated communication failures (default threshold 3), and access-log synchronization failure. Each alert records device details, severity, first and last occurrence, occurrence count, message and acknowledgement (who and when). Repeated occurrences of the same alert for the same device inside the cooldown window increment the existing alert instead of creating a new one. Alerts are acknowledged individually or in bulk.

### H. Audit log

`/devices/activity` records device connected/disconnected (as status changes), refresh requested, refresh completed, refresh and reconnect results, diagnostics, monitoring-settings changes, cancellation, and alert acknowledgements — with the device, the administrator (or "System" for scheduled work), the timestamp, the outcome and the detail, filterable by date range, device, action, outcome and free text, and exportable to CSV (formula-injection safe, streamed in chunks).

### I. Permissions, validation and rate limiting

A `role` column (`admin`, `operator`, `viewer`) was added with a default of `admin`, so no existing account loses any access. Gates: `devices.view` (every authenticated account), `devices.manage` (administrators: refresh, reconnect, settings), `devices.diagnostics` and `devices.alerts.acknowledge` (administrators and operators). Inside the controller each action calls `Gate::authorize`, so authorization is server-side and the UI merely hides what is not permitted. Rate limits: 20 refresh/reconnect requests per minute per user, 30 diagnostics, 120 polls. All inputs are validated, every refresh/reconnect/diagnostic writes an audit row, device failures are reported with a fixed explanation instead of a raw stack trace, and credentials never leave `Terminal`'s encrypted column.

## 3. Existing features reused or improved

Reused as-is: door unlock/lock and door status endpoints, personnel sync and revocation with readback confirmation, access-log collection with checkpoints, attendance register, super fingerprints, failed-attempts view, personnel/attendance/event exports, login and password handling.

Improved: `terminals:ping` (interval, pause, retries, persisted status, safer metrics), `Terminals` list (shows the persisted status instead of an expired cache flag), dashboard "Active Terminals" (counts devices that answered an authenticated check), device page (now the full device dashboard, door controls only offered while the device is confirmed online, and hidden immediately if a refresh finds it offline), and access-log synchronization failures now also raise an alert.

## 4. Files, schema and endpoints

Migration: [`database/migrations/2026_10_29_120000_create_device_monitoring_tables.php`](../database/migrations/2026_10_29_120000_create_device_monitoring_tables.php) — adds the `terminals` status columns, `users.role`, and the tables `device_monitor_settings`, `device_refresh_runs`, `device_refresh_items`, `device_alerts`, `device_activity_logs`. All new columns have defaults or are nullable, and `down()` reverses everything.

New application code: `app/Services/DeviceHealthService.php`, `DeviceRefreshService.php`, `DeviceDiagnosticsService.php`, `DeviceConnectionService.php`, `DeviceAlertService.php`, `DeviceActivityLogger.php`, `NetworkReachability.php`, `DeviceErrorInterpreter.php`, `app/Jobs/ProcessDeviceRefreshJob.php`, `app/Http/Controllers/{DeviceController,DeviceAlertController,DeviceActivityController}.php`, models `DeviceRefreshRun`, `DeviceRefreshItem`, `DeviceAlert`, `DeviceActivityLog`, `DeviceMonitorSettings`, views `resources/views/devices.blade.php`, `resources/views/devices/alerts.blade.php`, `resources/views/devices/activity.blade.php`, stylesheet `public/css/devices.css`, config `config/devices.php`.

Changed: `app/Models/Terminal.php`, `app/Models/User.php`, `app/Http/Controllers/{DashboardController,TerminalController}.php`, `app/Services/TerminalEventSyncService.php` (optional alert dependency), `app/Console/Commands/PingTerminalsCommand.php`, `app/Providers/AppServiceProvider.php` (gates, rate limits, sidebar count), `resources/views/layouts/app.blade.php` (navigation, CSRF meta, alert bell), `routes/web.php`, `database/factories/UserFactory.php`, `phpunit.xml` (test-only device timings), `.env.example`.

| Method | Path | Route name | Purpose | Minimum role |
| --- | --- | --- | --- | --- |
| GET | `/devices` | `devices.index` | Dashboard with statistics, filters and rows | any account |
| GET | `/devices/statuses` | `devices.statuses` | Live status/statistics JSON for polling | any account |
| POST | `/devices/{terminal}/refresh` | `devices.refresh` | Check one device now | admin |
| POST | `/devices/refresh` | `devices.refresh.bulk` | Refresh all / offline / selected | admin |
| GET | `/devices/refresh-runs/{run}` | `devices.refresh.run` | Run progress and per-device results | any account |
| POST | `/devices/refresh-runs/{run}/cancel` | `devices.refresh.cancel` | Cancel pending checks | admin |
| GET | `/devices/{terminal}/diagnostics` | `devices.diagnostics` | Read-only diagnostics report | admin, operator |
| POST | `/devices/{terminal}/reconnect` | `devices.reconnect` | Re-authenticate the saved connection | admin |
| POST | `/devices/monitor-settings` | `devices.monitor.settings` | Interval, pause and alert cooldown | admin |
| GET | `/devices/alerts` | `devices.alerts.index` | Alert centre | any account |
| POST | `/devices/alerts/{alert}/acknowledge` | `devices.alerts.acknowledge` | Acknowledge one alert | admin, operator |
| POST | `/devices/alerts/acknowledge-all` | `devices.alerts.acknowledge-all` | Acknowledge every open alert | admin, operator |
| GET | `/devices/activity` | `devices.activity.index` | Device activity log | any account |
| GET | `/devices/activity/export` | `devices.activity.export` | CSV export of the filtered log | any account |

The existing door routes (`devices.door.open`, `devices.door.close`, `devices.door.status`) keep their URLs, names, behaviour and current permissions. The only change in that block is that `devices.door.status` now answers **503** with the same `{"error": "..."}` body when the terminal cannot be reached, instead of 500: an unreachable device is a device condition, not a server fault, and nothing in the application consumed the 500.

## 5. Operating requirements

* `php artisan schedule:work` (or a Windows scheduled task) keeps `terminals:ping` running; the command itself applies the configured interval.
* `php artisan queue:work` must be running for bulk refreshes to be processed. While a run is queued but not started for more than a minute, the dashboard explains that no worker appears to be running. Single-device refresh works without a worker.
* Run `php artisan terminals:ping --force` for an immediate check of every device from the command line.
* Local installation of the migration was applied on 29 October 2026; run `php artisan migrate` on other installations (additive, reversible).

## 6. Verification

* `php artisan test`: **74 tests passed (452 assertions)**, including the 25 new device-monitoring tests (174 assertions) and all pre-existing suites (access events, personnel device access, attendance register, pending sync, event sync, timeouts, authentication) unchanged. `vendor/bin/pint --test` passes on `app`, `tests`, `routes`, `config` and `database/migrations`.
* Covered by the new tests: dashboard statistics and unmonitored readers; live-status payload; individual refresh online (and that no other device is contacted); unreachable device with diagnostic message, first-failure counting and the guarantee that `last_seen_at` does not move; authentication rejection; busy response; standalone readers refused; refresh all (run totals, per-item results, audit rows); refresh offline only; refresh selected only; empty selection rejected; duplicate run refused; cancellation stopping further contacts and being audited; diagnostics for a reachable and an unreachable device (including that the stored status is not modified and no secret appears in the payload); reconnect clearing the client cache and being recorded separately; alert deduplication inside the cooldown; acknowledgement permissions and audit; synchronization-failure alerts; operator/viewer permission boundaries; settings persistence; scheduled interval and pause behaviour; a due scheduled check storing offline; activity-log filtering, CSV export and formula neutralisation.
* Browser verification used a throwaway SQLite database with four simulated devices (no hardware): the dashboard rendered statistics, filters and rows; a single refresh updated the row, badge, timestamps and statistics in place and hid the door controls of the device that had just gone offline; "Refresh offline" produced a completed run with per-device results and a summary; the diagnostics modal showed all layers for an unreachable device; an alert was acknowledged (open count 4 → 3, audit row written); the activity log listed refresh, refresh-offline, refresh-completed, diagnostics and acknowledgement entries; saving the monitoring interval persisted to the database and wrote an audit row. No console errors were reported. The throwaway database, its script and the temporary server were removed afterwards.

## 7. Limitations and unsupported operations

* **No hardware verification.** Every check in this phase used simulated or unreachable devices. Firmware acceptance of `/ISAPI/System/deviceInfo`, tolerance for repeated polls and real-world latency still need a connected terminal.
* **Remote restart is not implemented** (no documented authenticated call in the installed package) and must not be added without protocol documentation and a change freeze around door operation. Restart is never batched.
* **No gateway/controller entity** exists; the application talks to devices directly, so "controller unavailable" is reported as the device's own connection/synchronization failure.
* **Pull-only monitoring.** Terminals are polled; no ISAPI event-notification subscription is configured, so there is no push heartbeat. "Heartbeat" here means the configured check interval plus the failure threshold.
* **Concurrency is bounded in the application**, not by parallel processes: one run is active at a time, TCP probing is limited to 4 sockets, and authenticated reads are serialised. With a single queue worker, one run's devices are processed in order.
* **Bulk refresh needs a queue worker**; without one, a run stays queued (and can be cancelled).
* **Alert delivery is in-app only** (no e-mail, SMS or push). Deduplication uses per-device cooldown, not per-recipient subscriptions.
* **Device clock drift is not calculated.** The diagnostics panel reports the device's own clock value for comparison only.
* **Roles have no management UI.** Administrators can set `role` directly in the database (or with `php artisan tinker`); collections and exports are still visible to every authenticated account, as before.
* **Activity export is CSV only** (PDF was optional); attendance and access-event downloads are unchanged.
* **Standalone/Wiegand readers cannot be monitored** remotely; only their asset record is shown.
* The dashboard loads all devices in one page (no pagination), which suits the site's device count; a very large site would need server-side pagination in the table.

## 8. Recommended next phase

1. Verify against real hardware: run diagnostics and a bulk refresh during business hours, confirm latency and that door operation is unaffected, and record the firmware's exact failure codes.
2. Add terminal-side clock comparison (offset calculation) once the real `localTime`/`timeZone` format is confirmed, and warn when drift exceeds a threshold.
3. Introduce role management (list/edit accounts, assign admin/operator/viewer) plus scoping of exports and door control, then decide whether door commands should require an explicit permission.
4. Scheduled report e-mails or webhook delivery for critical alerts, with recipients and escalation, once thresholds are proven.
5. Maintenance/alerting integration: export device status and alert metrics (for example into an existing monitoring system) rather than adding another dashboard.
6. Bulk-import friendly pagination and server-side filtering on the device dashboard if the site grows beyond a few dozen devices.
7. Continue the outstanding roadmap items in [PROJECT_REVIEW.md](../PROJECT_REVIEW.md): revocations for deleted personnel, per-terminal event import jobs, attendance approval workflow.
