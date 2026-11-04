# Fingerprint attendance register

## Priority and current hardware

The main requirement is to identify the fingerprint owner and show their login and logout times. The installation currently uses a fingerprint terminal to unlock the door on entry and an anonymous exit button when leaving.

A successful fingerprint event identifies the enrolled employee. An exit-button event does not identify a person, so it cannot safely be assigned to that employee, the most recent entrant, or everyone inside. Automatic person-specific logout needs an authenticated exit scan, such as an exit fingerprint reader or an explicit authenticated OUT operation.

## What is implemented

Open **Attendance Register** in the sidebar (`/attendance`). The register shows:

- Fingerprint owner's recorded name and employee ID, keeping people with the same name separate.
- Terminal and local date.
- Login time: the first successful fingerprint entry for that owner, terminal and calendar day.
- Every distinct fingerprint scan timestamp under **View fingerprint scans**.
- Logout time when an operator has recorded a known departure; otherwise **Not captured**.
- Logout source, operator, reason, and the history of corrections.

Repeated scans on a day are grouped; the most recent fingerprint scan is not treated as logout. This is a daily door-entry register, not a calculation of continuous work time or individual visits. An entry after a manually recorded logout flags the row for review. The earlier logout is retained in the history.

The register includes confirmed fingerprint events supported by the existing event data: access-control major 5/minor 38, or an explicit AccessGranted event with fingerprint verification. Explicit denials and system events are excluded. Unknown firmware codes require verification before being added to the recognised set.

Names come from the event's recorded owner name, falling back to the personnel record linked by employee ID. A record with an employee ID but no available name displays **Unmatched employee**. No fingerprint templates are copied into the register.

## Use the register

1. Check automatic collection through **Monitoring** using **Collection status / import logs** in the register. The scheduler collects logs every minute while running; import older history manually by date range. See [operations](OPERATIONS.md) for coverage and startup details.
2. Choose a date range, terminal, department/access group, owner name/ID, or logout status, then select **Show register**. Department filtering uses the current personnel assignment.
3. For a known departure, expand **Record known logout** on that owner's row, enter the actual departure time and how it was confirmed, then save.
4. To correct it, use **Correct recorded logout** and supply a new reason. This appends a history record instead of overwriting the previous entry.
5. Download **CSV / Excel** or **PDF**. Both use the applied filters and include all matching rows, not just the current screen page. Apply changed filters before downloading.

Manual logout cannot precede the latest fingerprint scan, be in the future, or be more than 36 hours after the day's first entry. Next-day departures within that limit include the departure date. Register days still follow calendar dates rather than overnight shift assignments.

Manual logout uses the application's existing authenticated operator access. Dedicated HR/security roles and approval permissions remain roadmap work. Operators should record only departures they can substantiate.

## Downloads and limits

- CSV is UTF-8 with an Excel-compatible byte-order mark. It contains owner, ID, terminal, department, first entry, latest scan, logout, source, operator, reason, status, scan count, and timezone. Text that could be interpreted as a spreadsheet formula is prefixed with an apostrophe. Numeric IDs starting with zero are also prefixed to preserve their zeros. This is a `.csv` download, not native `.xlsx`.
- PDF is a printable landscape register with the owner and login/logout times, reporting filters, timezone, record count, source notes, and signature spaces.
- Maximum date range: 93 calendar days. Registers inspect at most 20,000 candidate events; choose a shorter range or one terminal if that limit is reached. PDF downloads support 500 rows; use CSV for larger registers.
- No record in a register does not prove absence. Terminal outages and incomplete imports can leave gaps. Late/absent calculations, payroll hours, and approval workflows are future work.

## Installation and time handling

Run the additive migration when installing this change:

```powershell
php artisan migrate --path=database/migrations/2026_10_28_000001_create_attendance_checkouts_table.php
```

It creates `attendance_checkouts` for operator-entered logout records. The migration was applied to this local project's database on 28 October 2026.

`ATTENDANCE_TIMEZONE` and `ATTENDANCE_DEVICE_TIMEZONE` both default to `Africa/Harare`. Original device timestamps with explicit offsets take precedence; timestamps without an offset use the configured device timezone. If the original payload has no time, the stored event timestamp is the fallback. Invalid event times cannot create a register entry.

The register does not rewrite historical imported events or change the application's global UTC timezone. Manual departure instants are stored in UTC and displayed in the attendance timezone.

Terminals with imported access events or attendance records are blocked from deletion through the application so their history is retained. Full terminal archival remains future work.

## Verification

The dashboard suite passes with 27 tests and 147 assertions, including 16 attendance tests. Coverage includes separate owners, anonymous exits, denied/system events, repeat scans, local-midnight boundaries, manual logout/correction history, invalid logout times, later-entry warnings, filtered CSV/PDF downloads, department/terminal filtering, backfilled scans, spreadsheet text protection, limits, and terminal history protection. Changed PHP files pass Laravel Pint.

On this host, PHP reports the Composer bootstrap as unreadable to PHPUnit's preflight even though it can require the same file. Verification used a temporary PHPUnit configuration with the original test suites/environment settings, preloading Composer directly. The application's normal PHPUnit configuration was left unchanged. Browser visual verification was unavailable because the connected browser could not reach the local preview server; the register's Blade rendering and PDF generation passed the feature tests.
