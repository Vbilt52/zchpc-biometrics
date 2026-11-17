# ZCHPC Access Control System Documentation

System design operations and software lifecycle

Release date 17 November 2026

## Purpose and release scope

This manual describes the ZCHPC biometric access control application for administrators, systems engineers, HR officers, auditors and maintainers. It explains how identities, door permissions, device events and login accounts are managed, how the release was verified, and how to deploy and recover the service.

The application now presents corrected personnel names in collected access logs, generates PDF, Excel and Word event reports, preserves Personnel list position after changes, and provides fixed navigation with a mobile drawer. Account lifecycle enforcement, audit filtering, report limits, dependency updates and query improvements are included in this release.

This is operational documentation for the implemented system. It does not certify compliance with Google engineering practices, an external security standard or a data centre accreditation scheme. Independent penetration testing, capacity testing and a witnessed disaster recovery exercise remain separate assurance activities.

## Document control

| Item | Value |
| --- | --- |
| Version | 1.0 |
| Intended audience | ZCHPC operations and development teams |
| Application source revision | 10f0abf on tavonga before documentation packaging |
| Framework | Laravel 12 with PHP 8.2 or later |
| Test baseline | 146 application tests and 934 assertions passed locally |
| Change process | Each completed task committed to tavonga and merged into main |

## Reading guide

The following sections cover architecture and data, roles and accounts, operator workflows, validation and audit behaviour, implementation quality, SDLC verification, deployment and recovery, and ongoing operations. The companion RELEASE_VALIDATION file records final deployment evidence and the deployed main revision.

<!-- page -->
# Architecture and data lifecycle

## Components and trust boundaries

Authenticated browsers communicate with the Laravel application over HTTPS. The application reads local records for searches, dashboards and reports; normal page browsing does not wait for door controllers. The application uses the repository's Hikvision ISAPI integration to collect events and apply supported device changes. Device credentials are encrypted at rest using the application key.

The scheduler starts collection, connectivity checks and retries. The queue worker performs background work. Both run as managed services and restart on service failure. The current deployment uses SQLite. Controllers can continue making local access decisions while the server cannot reach them, so local changes and device confirmation are tracked separately.

## Principal records

| Record | Responsibility |
| --- | --- |
| users | Login identities, roles, optional permissions and account state |
| personnel | Biometric personnel identities and corrected display names |
| terminals and doors | Controllers and channels belonging to each controller |
| personnel_terminals | Desired assignment and confirmation state per controller |
| access_events | Collected device events, employee ID, timestamp and original payload |
| terminal_event_syncs | Collection checkpoints, last success and current error |
| pending_terminal_syncs | Durable work awaiting device confirmation |
| access_audit_logs | Actor, action, subject, result, context and time of system changes |
| attendance_checkouts | Operator-recorded checkout information with a reason |

## Identity and event flow

The importer validates each device response and stores events with a terminal-specific deterministic event key. Overlapping collection windows can refresh the same local event without duplicating identical payloads. The collector advances its checkpoint only after successful import and reduces the window when a device returns more records than the import limit.

Reports resolve names using an exact employee ID match to Personnel, including archived personnel. A corrected local name takes precedence over the old device name. Unmatched events retain the supplied device name or show Unknown person. Names are never guessed from similar spelling or a partial identifier. Leading zeroes remain significant.

Door numbers are meaningful only within their controller. Reports resolve the controller and channel together. Authentication results and entry direction are separate fields; a granted event alone is not proof that someone physically entered a room.

<!-- page -->
# Roles and login account management

## Implemented role matrix

| Role | Personnel and reports | Device and access changes | Login accounts |
| --- | --- | --- | --- |
| Super Admin | Full access | Full access | Create and manage |
| Systems Engineer | Full access | Full access | Create and manage |
| Director and Deputy Director | Executive reports and audit oversight | Read only | No management |
| HR Officer | Create and edit personnel, removal, attendance and scoped audit | Cannot assign physical access through personnel editing | No management |
| Audit Officer | Read audit, access events and attendance | Read only | No management |
| Viewer | General authenticated read access | No changes | No management |
| Legacy Operator | General read access | Diagnostics and supported refresh operations | No management |
| Legacy Administrator | Preserved full administrative access | Full access | Create and manage |

Super Admin and Systems Engineer retain the system's existing full-administration policy. Do not assign these roles merely to provide read access. Viewer is not a personal self-service role: general authenticated directories and dashboards contain information about other personnel. A separate per-person privacy model would require further design and approval.

## Account lifecycle

An authorized administrator opens User Accounts, chooses Create Account, enters a unique username and email, chooses a role and issues a temporary password. Manual passwords require 12 to 128 characters. The application normalizes username and email case before uniqueness checks. Generated passwords are displayed once in the administrator's response and must be delivered through an approved private channel.

Temporary-password accounts must change their password before using other authenticated endpoints. Deactivated accounts lose access on their next request, including existing sessions. Unknown or missing roles do not inherit administrator privileges. Administrators cannot deactivate their own active account, and the final active Super Admin is protected by account-management checks.

Optional permissions are restricted to personnel.create, personnel.edit, personnel.deactivate, reports.executive and access.audit. Arbitrary permission strings are rejected. Personnel editing permission alone does not authorize granting device access. Account activity is linked by stable account IDs rather than matching names or username fragments.

<!-- page -->
# Operator workflows

## Search entry and access logs

Open Access Events and select a time range, controller or entrance, person, employee ID, or name. Use Granted access only to isolate successful authentication records or Failed attempts only for denied events. Date inputs and displayed event times use Africa/Harare. Review the collection status before concluding that a period contains no activity.

Each row shows the time, controller, entrance, corrected person name, employee ID, method, result and reported direction. Unknown direction remains Not reported. Failed identification can legitimately have no employee ID or person name. An offline device may have newer events that have not yet been collected.

Choose PDF, Excel or Document to export the same filters. Excel produces a native .xlsx workbook and Document produces a native .docx file. PDF supports up to 500 matching rows; Office reports support up to 5000. A larger selection returns a clear error and must be narrowed. There is no silent truncation.

## Edit and remove personnel

Open the required Personnel page and apply filters before choosing View or Edit. Save and Cancel return to that list context. Single and bulk removal also retain the page and filters. If the final row on a page is removed, the application returns to the last page that still exists.

For bulk removal, check individual people or the header checkbox, review the selected count, choose Delete selected and confirm the displayed names and employee IDs. Selection applies to the current page and is cleared by navigation. The application rejects empty, duplicate, malformed, unknown or stale selections before committing changes.

Removal archives the local identity and queues device deletions. Historical records remain and the employee ID stays reserved. Offline devices may retain access until the queued deletion is successfully applied and verified. Check synchronization status; an HTTP success response for local removal is not a device-deletion confirmation.

## Navigation and small screens

The header and navigation stay fixed while content scrolls. The desktop menu can collapse and expand, with its preference stored in the browser. On a phone, the menu opens as a drawer above a backdrop. Close menu, the backdrop or Escape dismisses it. Long tables scroll within their own container.

<!-- page -->
# Validation reporting and audit integrity

## Validation boundaries

Browser controls provide guidance, while the server enforces authorization and validation independently. Names, employee identifiers, date ranges, supported door channels, terminal selections and login-account fields have explicit rules. Device assignment IDs must refer to supported controllers. Duplicate selections are rejected. Personnel validity end dates cannot precede start dates.

Return navigation carries only known scalar list filters. The application constructs its own Personnel URL and does not accept an arbitrary destination URL. A forged return value cannot redirect an operator to another website.

## Audit interpretation

System Audit Logs describe who performed a change, what action occurred, the affected subject, outcome, supporting detail and network address. Relevant employee IDs, door/controller labels and bulk-removal counts are shown when recorded. Names in the audit trail are historical snapshots of the action. Event reports use current corrected Personnel names; these are intentionally different representations.

Login successes, logouts and account-management actions are recorded. Raw passwords and biometric template material must never be included in audit context. The application does not expose audit-edit or audit-delete operations. Database administrators can still modify the underlying database: this is not a cryptographically tamper-evident audit store.

Audit page filters and CSV downloads share the same query and HR visibility rules. Local calendar-day boundaries are converted to UTC for filtering and displayed back in Africa/Harare. The action picker is scoped to the operator's allowed records. Account-specific activity uses stable target IDs.

## Export integrity

| Format | Safeguard |
| --- | --- |
| PDF | Same filters and identity resolver, named entrances, explicit 500-row limit |
| Excel | Native workbook; employee IDs and supplied values written as text, preserving zeroes and preventing formulas |
| Word | Native document; escaped text and repeating table headers |
| Audit CSV | Incremental streaming, CSV escaping and neutralized formula-like prefixes |

Downloaded reports contain personnel and access information. Store and share them using the organization's approved access and retention arrangements. This release does not impose an automatic retention or deletion policy on historical data.

<!-- page -->
# Implementation and development quality

## Maintainable implementation

Laravel routes and gates enforce privileged operations. Account readiness middleware applies account-state rules consistently. PersonnelListState carries a small allowlist of list filters. TabularReportExporter provides native Office output, while the event model owns common name, entrance, result and direction behaviour. Shared audit query construction prevents the page and download from drifting apart.

Tests run against an isolated in-memory database with mocked hardware where needed. Production personnel were not removed to test deletion. The SDK sources remain in the same repository and are used by the application bootstrap. Preserve this directory relationship when packaging or deploying.

## Performance measures

Report pages use pagination and eager loading. Office and PDF exports have explicit row bounds. Audit CSV uses batches rather than building the entire history in memory. The dashboard's seven-day chart calculates daily totals in the database instead of loading every event timestamp into PHP.

Indexes support controller/time, employee/time and audit/time queries. Page views read collected data instead of polling hardware synchronously. CSS and navigation assets use file-version cache keys instead of a timestamp that defeats caching on every request. Background processes retain durable retry work for offline devices.

These changes reduce avoidable queries and memory use. They are not a measured capacity guarantee. Set an expected peak workload and latency target, then run load tests with representative data before significantly increasing deployment scale.

## Verification evidence

The local application suite passed 146 tests with 934 assertions. Focused report tests inspected the contents of generated Word and Excel archives, checked employee IDs with leading zeroes, corrected and archived names, entrance labels and formula-safe spreadsheet values. PDF generation and explicit report limits were tested.

Account tests cover role boundaries, account creation, unlisted permissions, duplicate normalized logins, deactivated sessions and mandatory password changes. Audit tests cover local-day filtering, scoped HR exports and account identity isolation. Personnel tests cover return-page preservation, atomic bulk removal, stale selections and offline retries.

Browser checks exercised desktop collapse/expand and a 390 by 844 phone viewport. The header stayed at the top while content was scrolled, the drawer opened and closed, and Escape returned focus to the menu button. PHP formatting, JavaScript syntax and Blade compilation were checked. Dependency audit reported no known advisories after updates on 17 November 2026.

<!-- page -->
# Software development lifecycle and acceptance

## Requirements and traceability

| Requirement | Design and implementation | Verification |
| --- | --- | --- |
| Correct names per entrance | Exact employee ID resolution and controller/channel lookup | EventReportIdentityTest |
| Export entry lists | Native Office writer and filtered PDF | EventLogWorkflowTest and ReportingIntegrityTest |
| Fixed expandable navigation | Fixed shell, desktop preference and mobile drawer | Desktop and mobile browser checks |
| Preserve Personnel position | Allowlisted list state and last-page fallback | PersonnelListNavigationTest |
| Reliable change audit | Shared query, timezone conversion and stable target IDs | AuditReportConsistencyTest and account tests |
| Role-based accounts | Gates, permission allowlist and readiness middleware | RoleBasedAccessControlTest and AccountLifecycleSecurityTest |
| Safe offline removal | Transactional archive, durable work and verified retry | BulkPersonnelDeletionTest and device-access tests |
| Efficient reporting | Aggregation, eager loading, pagination and explicit bounds | Regression suite and report-limit test |

## Lifecycle controls

Requirements analysis separated authentication events from evidence of physical entry, and local removal from confirmed device removal. Design retained existing identities and historical records while changing presentation and authorization boundaries. Implementation was divided into independently reviewable commits on tavonga and incremental merges to main.

Verification includes automated functional tests, negative authorization tests, file-format checks, browser checks, dependency review and deployment smoke checks. Deployment uses the reviewed main revision, validated dependencies, a maintenance window and a consistent database backup. Maintenance includes checking device collection checkpoints, services, application errors and outstanding retries.

## Operational acceptance

An authorized ZCHPC operator should compare a known recent entry against the corresponding controller and report, download each required format, edit a person from a later filtered page, and verify representative roles. Do not delete real personnel merely to complete acceptance testing; use an approved disposable test identity if a physical-device deletion test is needed.

An administrator should confirm backup restoration on a separate instance and record agreed recovery time and recovery point objectives. Independent penetration testing, sustained load testing and a physical-entry exercise were not part of the local automated suite. Do not represent these as completed assurance activities.

<!-- page -->
# Deployment and recovery

## Prerequisites

The VM needs access to the reviewed GitHub main revision, PHP 8.2 or later with GD, ZIP, XML, mbstring and the database driver, Composer, the configured HTTPS virtual host and the existing service accounts. Keep the Laravel application key and environment configuration private and stable. Changing the application key would make saved device credentials unreadable.

The release helper deploy/release.sh targets the existing /var/www/zchpc-biometrics installation. It is run by an administrator with the exact reviewed main commit SHA. It checks for concurrent deployments and tracked local changes, fetches main, prepares dependencies in a separate checkout and checks platform requirements before entering maintenance mode.

## Release sequence

1. Complete local tests, formatting, dependency audit and review. Push the task to tavonga and merge it into main.
2. Confirm the production network, PHP extensions and both managed workers. Verify the expected main commit.
3. Run the release helper with that full commit SHA. The helper prepares dependencies before taking the application into maintenance mode.
4. Stop the managed workers and create a consistent SQLite backup. Preserve the environment, preceding commit and vendor directory in a private release backup directory.
5. Advance the application to the reviewed main commit, install the prepared dependencies and apply the additive reporting-index migration.
6. Rebuild application, route and view caches, restore service operation, reload PHP and verify the login response.
7. Inspect the deployed revision, service health, report routes, live collection state and database integrity. Compare protected personnel and account fields against the release backup.

## Recovery

If the helper fails during the maintenance window, it attempts to restore the previous tracked code and vendor directory, rebuild caches and restart services. Reporting-index migrations are additive and can remain with the previous code. The helper retains the staging checkout and backups for investigation rather than deleting recovery material automatically.

If automatic recovery cannot complete, keep the application in maintenance mode, inspect the failure and restore the reviewed code and dependencies manually. Database restoration is a separate recovery decision because it discards changes made after the backup. Stop writers, preserve the current database, validate the chosen backup and document any lost interval before replacing data.

Backups must be copied to an approved separate storage location and periodically restored in a separate environment. A backup on the same VM does not protect against loss of the VM or its disk.

<!-- page -->
# Operations maintenance and known limits

## Routine monitoring

Check scheduler and queue service health, device online state, collection last-success times, checkpoints and errors. Review failed jobs and pending controller changes. Verify disk space, backup completion and application errors. Repeated unreachable errors require network or device diagnosis; they should not be cleared by marking a device online without a successful authenticated check.

At the release preflight, Main Entrance, Server Room and UPS Room were online and collecting events. Back Entrance was offline and had an older checkpoint. Its recent entries cannot be certified from the server until connectivity is restored and collection catches up. Check its power, link, address, VLAN path and device service before trusting a missing-record conclusion.

## Security and incident response

Review privileged accounts and optional permissions periodically. Deactivate accounts when access is no longer needed and investigate unusual changes using actor, subject, time and source address. Secure device and application credentials, restrict management networks, and use encrypted transport where supported. Do not publish database files, environment files, exported personnel reports or credential material in GitHub.

For a suspected unauthorized entry, preserve the original controller event, local event, audit records, synchronization state and relevant timestamps. A report's corrected name should be reconciled with its employee ID and original event evidence. For a suspected application incident, preserve logs and backups, revoke affected accounts, assess the scope and use the documented recovery procedure.

## Maintenance and change review

Run the test suite and dependency audit before each release. Check supported PHP and framework versions and review security advisories. Keep future changes small, with a concrete problem statement, tests that cover changed behaviour and a rollback plan. Review deployment-script assumptions when service names, paths, hosting topology or database technology change.

The current full-administration policy for Systems Engineers, broad authenticated read access, application-level audit storage and SQLite deployment are explicit architectural choices. Assess whether stronger role separation, per-person privacy, centralized immutable audit storage, multi-factor authentication and a managed database are required for the site's risk profile. These capabilities are not claimed as implemented in this release.

## Technical references

Laravel authorization documentation: https://laravel.com/docs/12.x/authorization

PHPWord document writers: https://phpoffice.github.io/PHPWord/usage/writers.html

PhpSpreadsheet explicit cell types: https://phpspreadsheet.readthedocs.io/en/stable/topics/accessing-cells/

Repository evidence: application tests, routes, controllers, models, migrations, deploy/release.sh and docs/RELEASE_VALIDATION.md.
