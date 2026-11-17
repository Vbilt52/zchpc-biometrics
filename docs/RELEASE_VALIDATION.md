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

The release helper prepared and checked dependencies before maintenance, took a consistent SQLite backup, preserved environment configuration and the preceding vendor directory, applied only the additive reporting-index migration, rebuilt caches and checked the live login response.

## Completed deployment verification

Application release `c846727e97f3f3b78e8871e8982f6e4c2c170a97` was deployed successfully. This validation record and a role-comment clarification are a subsequent documentation-only revision; they do not change application behaviour.

- The HTTPS login returned **200**. Thirteen read-only requests through the production application's HTTP kernel returned **200**, covering four dashboards, Personnel page 2, Users & Access page 2, audit, account list/create form, events and PDF/XLSX/DOCX exports. The checks used an existing authorized actor and an in-memory session; no accounts or personnel were created or removed for testing.
- PDF signatures and native Office archive contents passed. Sample export generation took 26–134 ms in the warm check; this is a small smoke sample, not a load-test or end-to-end browser latency claim.
- All **1,687** collected events with an exact matching Personnel identity resolved to its current name. **994** differed from the original recorded name and correctly used the Personnel name. Unmatched identifiers remain explicit rather than being guessed.
- All **150 personnel rows** matched the immediate release backup for internal ID, employee ID, name and archived state. All **8 login accounts** matched for IDs, names, usernames, email, password hashes, roles, optional permissions, active state and required-password-change state. Live records created before the maintenance backup were preserved.
- SQLite integrity returned **ok**. Both new report indexes are present, and the additive migration completed.
- Scheduler, queue and PHP-FPM services are **active**. Main Entrance, Server Room and UPS Room had successful collection checkpoints at 21:07:50–21:07:51 Africa/Harare, with successful collection at 21:08:00–21:08:02 on 17 November 2026. Back Entrance remained unreachable and retries remain enabled.
- The consistent backup is `/home/user/zchpc-backups/release-20261117T190338Z/database.sqlite`. The environment, preceding commit and vendor directory were retained privately with it. This directory is not committed to Git.

The production Composer executable emitted deprecation notices from its own distribution. Dependency installation, platform requirements, optimized autoload generation and the release all completed successfully. Update the host Composer package through normal host maintenance.

## Assurance limits

No production personnel were removed to test this release. Offline controllers may retain access until queued changes are verified by the device. Software cannot guarantee continuous connectivity when controller power or network connectivity fails.

This release does not constitute Google certification, an independent penetration test, a sustained load test or a witnessed disaster recovery exercise. Authentication events do not alone prove physical entry. Back Entrance's recent entries cannot be certified until collection resumes.
