# Release validation — 17 November 2026

Application changes are complete through `10f0abf` on `tavonga`, merged to `main` as `d385d65`. Documentation and the guarded release helper are packaged separately.

## Local verification

- Laravel application suite: **146 tests passed, 934 assertions**; 32.73 seconds.
- PHP formatting passed; navigation JavaScript syntax checked; Blade views compiled.
- Composer manifest/lock validation passed (the existing local SDK uses a development path version). Dependency audit reported no known advisories after supported dependency updates.
- Generated XLSX and DOCX contents were inspected by regression tests; PDF generation, report row limits, corrected/archived names, entrance resolution, formula safety and leading-zero employee IDs passed.
- Authorization tests cover roles, account lifecycle, forced password change, inactive sessions and allowed permissions.
- Personnel tests cover retained page/filter context, atomic bulk removal and offline retries, using an isolated in-memory database and mocked devices.
- Desktop and 390 × 844 mobile browser checks passed for fixed navigation, collapse/expand, drawer dismissal, scrolling and focus return.
- The nine-page Word manual was rendered with Microsoft Word and reviewed page by page for readable tables, margins and page breaks.

## Production release checks

SSH connectivity was restored after the workstation VPN was switched off. The VM passed the PHP extension and managed-service preflight. Main Entrance, Server Room and UPS Room were online and collecting events. Back Entrance remained unreachable, with its last collection checkpoint on 13 November.

The release helper prepares and checks dependencies before maintenance, takes a consistent SQLite backup, preserves environment configuration and the preceding vendor directory, applies only the additive reporting-index migration, rebuilds caches and checks the live login response. Post-release verification must record the deployed commit, data-preservation comparison, report responses, services and collection state. The final verification record is appended after deployment.

## Assurance limits

No production personnel were removed to test this release. Offline controllers may retain access until queued changes are verified by the device. Software cannot guarantee continuous connectivity when controller power or network connectivity fails.

This release does not constitute Google certification, an independent penetration test, a sustained load test or a witnessed disaster recovery exercise. Authentication events do not alone prove physical entry. Back Entrance's recent entries cannot be certified until collection resumes.
