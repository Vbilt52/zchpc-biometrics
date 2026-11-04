# Collection and account tools

## Device monitoring and recovery

See [Device monitoring](DEVICE_MONITORING.md) for the device dashboard, status model, manual/bulk refresh, diagnostics, alerts, audit log, permissions and limitations. The additive migration `2026_10_29_120000_create_device_monitoring_tables.php` was applied locally on 29 October 2026; it does not alter existing terminals, permissions or access records.

`php artisan schedule:work` runs `terminals:ping` every minute; the command only contacts a device once the interval configured on the device dashboard has elapsed. Keep a queue worker (`php artisan queue:work`) running for “Refresh all”, “Refresh offline” and “Refresh selected”: they are processed in the background so a slow terminal cannot hold a browser request. A single-device refresh works without a queue worker. If a run stays queued, the dashboard reports that no worker appears to be running and allows the run to be cancelled.

Check every device immediately from the command line with `php artisan terminals:ping --force`, or one device with `php artisan terminals:ping --device="Main Entrance" --force`.

Online means the device answered an authenticated ISAPI read. A reachable port or a cached flag never marks a device online. Refresh only reads device information; Reconnect clears this application's cached connection and authenticates again. No new action restarts a device, unlocks a door, or changes access permissions, and no remote restart is offered because the installed device API has no documented reboot operation.

## Device assignments

See [Device assignments and failed attempts](DEVICE_ACCESS.md) for editing per-device permissions, access dates, confirmation states, offline retry behavior and failed-attempt downloads. The additive 29 October migration preserves existing project records. All four devices were offline during UI verification, so physical enforcement still needs a connected-device check.

## Automatic access-log collection

Keep `php artisan schedule:work` running alongside the web server. The scheduler starts `terminals:sync-events` every minute. A persistent Windows service or scheduled task is still needed for unattended collection after a reboot; the development processes are not installed as services.

Monitoring shows each ISAPI terminal's collection status, automatic coverage, last successful collection, and connection errors. A collection more than five minutes old is flagged as overdue. Refresh the page to see updated status.

Each terminal starts automatic coverage at midnight in Africa/Harare on its first collection day. Use Monitoring's manual date-range import for older records. The worker reads bounded time windows, advances its checkpoint only after a complete response, and retries outages from the saved checkpoint. Each run overlaps the previous checkpoint by two minutes to pick up recent delayed records. Events arriving later than that overlap require a manual backfill.

Imports deduplicate by terminal and payload. Manual and automatic imports share the same importer. Each request is capped at 5,000 events; automatic collection retries a shorter window when that cap is reached. Unrecognized or incomplete device responses leave the checkpoint in place. Collection cannot retrieve records already deleted by the device. Retain regular database backups.

Run a single collection manually from the dashboard folder:

```powershell
php artisan terminals:sync-events
```

The additive migration `2026_10_28_000002_create_terminal_event_syncs_table.php` creates the collection status table. It was applied locally on 28 October 2026. New imports store event instants in UTC and display them in the configured attendance timezone. The original device timestamp remains in the event payload. Existing rows are normalized when reimported.

The live check on 28 October found all four configured terminals unreachable. Retry status was verified; successful collection from the physical devices remains to be verified when their network is available. The local web server and scheduler are running.

## Password changes and recovery

Use **Account Settings** to change your own password. The current password and matching confirmation are required. Passwords must contain 12–128 characters. A successful change signs you out, rotates remembered-login credentials, and deletes the account's database sessions. Authenticated routes also check the session's password hash. Login and password-change submissions are limited to six per minute per IP address.

If a password is forgotten, a trusted operator with access to the application server can recover the exact existing username:

```powershell
php artisan account:recover prince
```

`prince` is an example username. Use `admin` for the existing local administrator. The command asks for the new password twice using hidden prompts; do not put passwords in command-line arguments. Recovery changes only that account's password and sessions. It does not recreate the database, seed accounts, or delete personnel, attendance, or terminal records. A password-change audit entry records the account ID and source without the password.

Recovery by email is not configured. The login page directs users to their server administrator.

## Branding and examples

The full official ZCHPC logo (emblem, wordmark, and tagline) is displayed in a 220-pixel frame on login/setup and a 200-pixel frame in the sidebar. Short screens use a 180-pixel login frame. Prince is used in sample person names and search placeholders. Existing account names and personnel identities remain real records and are not replaced with sample data.

## Verification

36 tests / 197 assertions passed against an isolated in-memory database. The new tests cover search paging, deduplication, timezone conversion, malformed responses, retry checkpoints, import limits, current-password checks, confirmation, recovery isolation, and session revocation. Browser verification covered the larger sidebar logo, Account Settings, and Monitoring collection status.
