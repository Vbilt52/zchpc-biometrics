# Project review and improvement roadmap

Reviewed: 28 October 2026

Latest follow-up: [Existing feature improvements, 29 October](docs/EXISTING_FEATURE_IMPROVEMENTS.md). Automatic collection, collection status, password changes, server-side account recovery, and login throttling are now implemented. The original findings below retain the historical review context; the new follow-up identifies the remaining priorities.

Device monitoring follow-up, 29 October: the terminal health findings above (cache-only status, no timestamps, no manual checks, no alerts, no device audit, no server-side roles) are now implemented additively — see [Device monitoring](docs/DEVICE_MONITORING.md). Role management has no UI yet, exports and door control keep their previous permissions, and hardware verification is still outstanding.

## Implementation follow-up: fingerprint owner and times

The user clarified that identifying the fingerprint owner and their entry/logout times is the priority. The actual hardware is a fingerprint door-unlock terminal on entry and an anonymous exit button. Exit-button events cannot establish who left.

An Attendance Register is now implemented with owner name/employee ID, first daily fingerprint entry, repeated scan details, explicit missing logout, and operator-confirmed logout with an append-only correction history. CSV and PDF downloads use the same filters as the screen. Times use Africa/Harare by default. A terminal with historical access/attendance records is now blocked from deletion through the application. The local attendance-table migration has been applied.

See [Attendance register usage and limitations](docs/ATTENDANCE_REGISTER.md). Automatic identified logout still requires an authenticated exit scan. Manual event import, roles, automated collection, shifts and other roadmap items below remain outstanding.

## Scope

Initial source review of the Laravel dashboard in `zchpc-dashboard` and relevant parts of the local Hikvision ISAPI package in `../hikvision-isapi-master`. Reviewed routes, controllers, models, migrations, scheduled commands, views, and existing tests. The initial findings below describe the starting point; the implementation follow-up above records subsequent changes. Hardware behaviour was not verified.

## What already exists

- Login by username or email, initial administrator setup, and session logout.
- Personnel records, department/access groups, validity periods, and terminal assignments.
- Terminal management and remote door controls for supported API terminals.
- Fingerprint enrollment and terminal administrator fingerprint management.
- Personnel import/sync, including persistent retries for failed personnel upserts.
- Locally stored access events with filtering, pagination, and duplicate detection based on a terminal-specific payload hash.
- Access-event PDF and HTML-based Word `.doc` downloads.
- Personnel CSV and HTML-based Word downloads.
- Scheduled terminal health checks and pending personnel-sync retries.

At the initial review, the access-event report was an audit log and there was no attendance register or attendance-specific download route. The follow-up adds the fingerprint register and downloads; shift calendars and attendance calculations remain future work.

## Confirmed issues to prioritise

| Priority | Finding and evidence | Proposed improvement |
| --- | --- | --- |
| High | Deleting a terminal deletes its historical access events: `database/migrations/2026_09_02_000004_create_access_events_table.php` uses `cascadeOnDelete()`, and `TerminalController::destroy()` deletes the terminal. | Archive terminals and preserve logs. Retain terminal identity/location snapshots for historical reports. |
| High | `PersonnelController::destroy()` removes the local person even when device deletion fails. `PendingTerminalSyncService` queues/retries upserts only, and its rows depend on the personnel record. | Add durable revoke/delete operations that retain employee/device identifiers independently of a deleted person. Show unresolved revocations until confirmed on every relevant device. |
| High | Routes use authentication, but no application role/policy checks were found for door control, exports, personnel changes, or super-fingerprint enrollment. | Add Admin, HR, Security, and read-only roles with server-side policies. Audit sensitive actions and restrict exports by scope. |
| High | Login has no explicit attempt throttling in the reviewed route/controller. | Add login rate limits and account recovery; consider MFA for administrators and operators with sensitive permissions. |
| High for attendance | `config/app.php` fixes the application timezone to UTC; filters use local datetime inputs without an explicit timezone. | Define Africa/Harare as the attendance/reporting timezone, standardise stored timestamps, and convert terminal timestamps consistently. Verify midnight boundaries before trusting daily totals. |
| Medium | `PingTerminalsCommand` gets personnel counts, event counts, and recent events from only `$firstOnlineClient`. Cache defaults can display zero when data is unavailable. | Count registered people from the local personnel database; report imported events across all terminals. Display last successful refresh and stale/unknown states separately from genuine zero totals. |
| Medium | Dashboard badges disagree with `AccessEvent::methodLabel()`: the dashboard calls minor codes 1 and 75 fingerprint events, while the model calls them card and face. | Centralise event decoding and verify major/minor combinations against actual device payloads. Preserve unknown codes instead of guessing. |
| Medium | `EventController` imports events synchronously, caps each terminal at 5,000 records, and has no scheduled event-ingestion task. `importAll()` does not surface individual truncation flags. | Add per-terminal background import jobs, persistent checkpoints, overlap/deduplication, resumable backfills, and visible completeness/failure indicators. |
| Medium | Event identity hashes the complete payload, so a changed optional field can produce another stored record for the same underlying scan. | Prefer a verified stable device event identifier where available, scoped to terminal and any reset/session boundary. Define and test a fallback identity. |
| Medium | Event exports load all matching rows into memory before PDF/Word generation. Personnel CSV similarly loads the full collection before streaming. | Stream CSV in chunks; queue large exports and offer a download when ready. Set sensible PDF limits and report totals clearly. |
| Medium | Personnel export methods do not accept the list filters; they export all personnel. | Share the list query with exports and show whether the download covers filtered records or everyone. Include department in exports. |
| Medium | CSV fields are written directly from stored text. | Protect spreadsheet exports against formula interpretation of text beginning with formula characters, and preserve employee IDs as text. |
| Medium | Event time parsing can produce a null timestamp; date-filtered searches then omit that record. | Record invalid/missing timestamps as import exceptions with a visible review queue. |
| Maintenance | Dashboard README remains the Laravel starter README. | Document installation, device setup, scheduler/worker operation, supported devices, backups, restore, and troubleshooting. |

## Attendance register: proposed first feature

Add an Attendance navigation item with daily, weekly, and monthly views, employee search, department/site filters, and date presets.

Recommended daily columns:

`Date | Employee ID | Name | Department | Shift | First In | Last Out | Worked Hours | Late Minutes | Early Departure | Status | Exceptions`

Statuses should include Present, Late, Absent, Leave, Holiday/Rest Day, Incomplete, and Awaiting Sync. An employee can be present with a separate lateness flag; avoid making these mutually exclusive in the underlying model.

### Calculation requirements

1. Configure attendance sources by terminal and reader/door where necessary: entry, exit, or excluded. Internal room access must not automatically count as arrival or departure.
2. Count confirmed successful, person-linked events only. Exclude denied attempts, system events, and exit-button events without a person identity. Validate device event mappings first.
3. Support repeated scans, multiple in/out sessions, missing exits, overnight shifts, grace periods, and configurable breaks. Calculate worked hours from valid pairs; first/last timestamps alone do not prove continuous work.
4. Build expected attendance from employment dates, schedules, and approved leave/rest days. Access-card validity alone is not an employment calendar.
5. Do not mark people absent while an attendance terminal has incomplete imports. Display Awaiting Sync or a completeness warning until the relevant interval is reconciled.
6. Keep source events immutable and trace each daily result back to its events. Record rule versions and reviewed corrections separately.
7. Support corrections with a reason, original value, editor, timestamp, and approver. Lock approved periods and require an explicit reopening process for late-arriving data.
8. Keep stable personnel identities and historical department/shift assignments so employee-number or department changes do not rewrite old reports.

### Attendance downloads

- CSV: quick analysis and integration with other systems.
- Excel `.xlsx`: daily register plus a monthly totals sheet, readable formatting, and text-safe employee IDs.
- PDF: printable register with organisation name, reporting period, department, timezone, generated time, completeness status, totals, and signature/approval fields.
- Optional genuine `.docx`: editable formal reports if needed; current Word exports contain HTML in a `.doc` file.
- All formats must use the same filters and calculation rules as the screen, cover all matching rows rather than only the visible page, and enforce download permissions.

Acceptance checks: repeated scans do not inflate hours; denied events never create attendance; an offline terminal cannot cause a false absence; overnight work belongs to the correct shift; leave/rest days are respected; approved corrections are traceable; downloaded totals match the screen for identical filters and data versions.

## Other useful improvements

- Dashboard cards for expected staff, present, late, absent, missing exits, and awaiting-sync records, with clickable drill-downs.
- A terminal health page showing last seen, last event import, import coverage, clock drift, pending syncs, and actionable failures.
- A reconciliation screen for local personnel versus each device, including unexpected profiles and unresolved revocations.
- Approved leave, public-holiday calendars, shift assignment, and overtime review before payroll integration.
- Alerts for meaningful failures such as offline attendance terminals, import gaps, repeated denied access, and pending revocations. Define recipients and thresholds before enabling delivery.
- Visitor check-in, host, access expiry, and checkout tracking as a later module.
- Emergency occupancy/muster view only where entry/exit capture is sufficiently complete; show uncertainty prominently.
- Bulk personnel CSV/Excel import with preview, validation, duplicate detection, and a downloadable error report.
- Scheduled backups, tested restoration, documented retention, and audit history for exports, attendance edits, personnel changes, door commands, and administrator fingerprint actions.
- Mobile-friendly filters/tables, accessible field labels, clear progress feedback, and locally hosted logo/icon assets for operation without public internet.
- Separate department membership from door-access groups if staff need multiple access permissions or historical department reporting.

## Recommended delivery order

1. **Protect and standardise data:** preserve logs, retry revocations, add permissions/login throttling, resolve timezone and event decoding, and establish backup/restore procedures.
2. **Make event collection reliable:** background incremental imports, completeness tracking, resumable history, and accurate dashboard metrics.
3. **Deliver attendance and downloads:** daily/monthly register, minimal work schedules, CSV/Excel/PDF, and missing-scan handling.
4. **Add approvals and HR workflows:** corrections, leave, holidays, complex shifts, monthly approval/locking, and overtime review.
5. **Extend operations:** alerts, reconciliation, visitor workflows, and payroll integration where required.

Start with one department and verified attendance readers. Reconcile a sample period against an independently checked register before expanding.

## Verification notes

Existing dashboard tests cover authentication, access groups, pending upserts, event import/report downloads, terminal timeout behaviour, and fingerprint workflows. Attendance-specific tests do not exist yet.

The initial dashboard and package test attempts stopped at PHPUnit's bootstrap readability check. During implementation, dashboard verification succeeded by preloading Composer directly and using a temporary configuration preserving the test suites and environment settings. All 27 dashboard tests passed (147 assertions), including 16 new attendance tests. Changed PHP files pass Pint. No working hardware connection is claimed. A clean CI test run remains maintenance work.
