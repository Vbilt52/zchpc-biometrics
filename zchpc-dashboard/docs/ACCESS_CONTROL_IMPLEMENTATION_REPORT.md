# Remote Enrollment, User Management and Door Access — Implementation Report

**System:** ZCHPC Access Control Suite (`zchpc-dashboard`)
**Date:** 30 October 2026
**Status:** Implemented, automated tests and HTTP smoke test passing; live-controller acceptance testing still required.

---

## 1. What already existed

The application was not rebuilt. The following working areas were inspected and left in place:

| Area | Existing capability |
|---|---|
| Terminal management | CRUD for controllers/readers, encrypted device passwords, per-terminal timeout and protocol |
| Device monitoring | Background `terminals:ping`, bulk refresh, alerts, device activity log, diagnostics |
| Hikvision integration | `shaykhnazar/hikvision-isapi` client for UserInfo, events, door control and fingerprint APIs |
| Personnel | CRUD, CSV/Word export, device import, legacy per-channel access (`door_right` / `right_plan`) |
| Offline writes | `pending_terminal_syncs` queue with retry/backoff and per-controller state |
| Events | Access-event import, checkpoints, duplicate suppression, failed-attempt view |
| Attendance | Register derived from access events with operator checkout |
| Super fingerprints | Existing terminal administrator-fingerprint workflow |
| Access groups | Department/group records used by personnel |
| Roles | `admin`, `operator`, `viewer` on users, with device-management gates |

The device inventory is held in the `terminals` table. The integration available in this codebase is **Hikvision ISAPI access control**; standalone/Wiegand readers are marked as non-API devices and are deliberately excluded from remote operations. Exact terminal models and firmware are site data, not hard-coded in the application.

---

## 2. Hardware and protocol capability assessment

### Verified in code / protocol contract

| Operation | ISAPI endpoint used | Confirmation method |
|---|---|---|
| Read person | `POST /ISAPI/AccessControl/UserInfo/Search` | Parsed response must be `OK` or `NO MATCH` |
| Create person | `POST /ISAPI/AccessControl/UserInfo/Record` | Person is read back |
| Modify person | `PUT /ISAPI/AccessControl/UserInfo/Modify` | Read back and field comparison |
| Remove person | `PUT /ISAPI/AccessControl/UserInfo/Delete` | Absence is read back |
| Fingerprint capability | `GET /ISAPI/AccessControl/FingerPrintCfg/capabilities` (fallback `/FingerPrint/capabilities`) | Stored as `supported` / `unsupported` / `unknown` per terminal |
| Remote capture | `POST /ISAPI/AccessControl/FingerPrint/SetUp` | Fingerprint slot list is read back with `POST /FingerPrint/Search` |
| Door release | Existing door-control endpoints | Unchanged, independent of enrollment |

### Important conclusions

1. **Remote capture is not assumed.** Each terminal carries `fp_capability`. The probe asks the device; `unknown` devices are attempted, and a 404/405/“not support” answer marks the device `unsupported`. Unsupported terminals are hidden from enrollment choices or shown with the local-enrollment alternative.
2. **A successful capture response is not treated as success.** An enrollment is `confirmed` only after the terminal’s own fingerprint list contains the requested slot.
3. **Fingerprint templates are not exported or copied between terminals.** The integration exposes no verified template export/import operation, and templates must remain on the device. Each terminal where a person needs fingerprint access requires its own capture. Door permissions, by contrast, are synchronized to every controller that enforces a granted door.
4. **No raw fingerprint images or templates are stored.** The application stores the person, finger slot, terminal, status, timestamps, requester and a redacted device-status summary only.
5. **No live hardware was available in this environment.** Tests use the real service/controller code with mocked ISAPI clients. Capability and enrollment behavior must still be accepted on one non-critical terminal before rollout. Do not claim a physical enrollment has succeeded until the device read-back confirms it.

### Schedules and plan templates

The terminal’s door plan uses the door’s `plan_template_no`. That template is where the device stores its day/time schedule. The application can choose the template per door and per grant, but it does not edit device time templates. Person validity is bounded by `begin_time` / `end_time`, and individual grants can carry an expiry. Per-grant day-of-week schedules are therefore a **device-template configuration task**, documented as a limitation rather than simulated in the database.

---

## 3. Implemented features

### 3.1 User Management

- **Access → Users & Access** list with search, department and enrollment filters, status, assigned-door count, fingerprint state, controller state and last event.
- **Add New User** wizard with six interface steps and a verification page:
  1. Person details
  2. Enrollment device
  3. Fingerprint capture (or explicit skip)
  4. Authorized doors
  5. Review
  6. Save & apply → verification
- Duplicate employee numbers are rejected. Similar names require an explicit duplicate-confirmation checkbox.
- Draft onboarding is resumable and can be discarded. A draft has no door access and is not synchronized.
- Profile photo upload (JPEG/PNG/WebP, size-limited) with authenticated photo route; no public storage link.
- Status, activation and expiry dates, department, phone, email and notes.
- Person profile shows personal details, biometric state, controller state, door grants, expiry, access history and the grant/revoke/suspend/reactivate/re-enroll/delete controls.
- **Least privilege:** new people start with zero door grants.
- **Responsive layout:** the sidebar becomes a horizontal navigation bar on tablets/phones, two-panel access screens stack, tables scroll horizontally, and wizard steps stay in one scrollable row.

### 3.2 Remote fingerprint enrollment

- Dedicated capture job (`EnrollFingerprintJob`) so a 60-second blocking capture does not hold an HTTP worker.
- States: `pending` → `waiting` → `confirmed` / `failed` / `timed_out` / `cancelled`.
- Per-enrollment progress display, polling endpoint, retry and cancellation.
- One active capture per terminal at a time; a second request gets a clear conflict message.
- A cancellation during a blocking capture wins over the late device response.
- Enrollment is only allowed on an API-managed, online terminal that has not been proven unsupported.
- Before capture, the employee identity is created on the terminal in a **disabled** state, with no usable door plan. The disabled state is read back. If the device will not keep it disabled, enrollment stops rather than provisioning access.
- Multiple fingers per person are supported (slots 1–10).
- If remote capture is unavailable, the UI explains the supported fallback: enroll locally on the terminal and import the person, then grant doors separately.

### 3.3 Door and controller registry

- `doors` table maps a physical door to one controller and one channel (`terminal_id` + `door_no`), with building, floor, zone, plan template, active state and display order.
- Doors without an API controller are visibly non-deployable and cannot be granted.
- Create/update/remove door workflow; a door with grants cannot be removed until those grants are revoked.
- Controller channel provisioning from a terminal’s door status is available, so doors are not guessed.
- Grouping by building, floor, zone and controller in the selection tree.

### 3.4 Door permission assignment

- Door checkboxes grouped by area and controller, with select-visible/clear controls and access-group shortcuts.
- Grant one, several or all eligible doors; revoke selected doors only.
- Optional per-grant expiry and plan-template override.
- **Granting does not depend on a fingerprint**: biometric and door permissions are separate by design.
- Removing one door leaves unrelated grants and device plans untouched.
- Suspension blocks every assigned controller while retaining grants and enrollment records.
- Reactivation restores only doors whose grants are still present, unexpired and in service.
- Re-enrollment changes the credential only; it does not alter door grants.
- Deletion requires typed confirmation and a checkbox. Every assigned controller must confirm removal before the local row is deleted; otherwise deletion stops and the profile remains. Photo and credential metadata are removed on successful deletion.

### 3.5 Controller deployment and synchronization

- Local grant intent is written first; each affected controller is queued separately.
- Per-controller states distinguish **configured**, **pending**, **confirmed**, **revocation pending** and **failed**.
- Offline controllers remain `pending`; the UI never reports them as applied and warns that a controller may still enforce previously stored permissions.
- Retry/backoff queue (`pending_terminal_syncs`) plus a per-person lock prevents duplicate concurrent writes.
- Sync scopes: one person, selected people, all onboarded people and all pending writes.
- Idempotent upsert: a single employee record per controller, read back and compared; unrelated device users and plans are not touched.
- Revocation writes a blacklist/disabled state while preserving other users’ records.
- Sync history is recorded in `device_sync_events` and surfaced on the synchronization page.
- A scheduled `access:expire-grants` command removes expired grants and queues the corresponding plan reduction/revocation.

### 3.6 Access matrix, history and audit

- **Access Matrix** with search, department filter, door filter, per-door/per-person views and CSV export.
- Cells and legend distinguish **Allowed** (configured and confirmed), **awaiting controller** (configured, not confirmed), **pending** and **denied**.
- Profile access history is read from the existing `access_events` table (door, time, result, terminal, details); the existing dedupe import is reused.
- Audit log records logins/actions with actor, subject, door, controller, IP, outcome and JSON context. Biometric templates are never passed to the logger.
- Audit actions include person create/update/delete, onboarding, permission grant/revoke/expire, suspend/reactivate, enrollment start/confirm/fail/cancel/skip, credential sync, door changes and capability probes.

### 3.7 Authorization and safety

- Mutating routes require the `access.manage` gate (**administrator only**); audit requires `access.audit` (administrator only). Read-only views use `access.view` for authenticated accounts, consistent with the existing monitoring model.
- Every mutating form has CSRF protection and server-side validation.
- Suspension, revocation, deletion and draft discard require explicit confirmation.
- Device passwords remain encrypted at rest by the existing terminal model cast; the app key must be protected.
- The module does not touch emergency release or existing door-control routes.

---

## 4. Database changes

New migrations:

| Migration | Purpose |
|---|---|
| `2026_10_29_000001_add_device_access_state` | Adds per-terminal assignment state used for permission deployment |
| `2026_10_30_120001_create_doors_table` | Physical doors and controller channels |
| `2026_10_30_120002_create_access_control_tables` | Door grants, access-group doors, fingerprint enrollments, audit logs, device sync events |
| `2026_10_30_120003_add_access_profile_fields` | Person status/photo/contact/notes fields and terminal fingerprint capability columns |
| `2026_10_30_120004_backfill_legacy_door_grants` | One-time conversion of existing channel rights into door grants |

The backfill preserves existing access intent: for every existing person/terminal assignment, legacy `door_right` channels are matched to doors (created with a clear `Door N` label if absent) and recorded as local grants. It **does not contact devices**. Those grants are shown as unverified until a controller confirms them; this is deliberate, because the old database never proved device state. Review the generated door names/locations after migration.

---

## 5. Main files

**Controllers:** `AccessUserController`, `AccessWizardController`, `AccessEnrollmentController`, `AccessMatrixController`, `AccessSyncController`, `AccessAuditController`, `AccessGroupController`, `DoorController`.

**Services:** `FingerprintEnrollmentService`, `AccessProvisioner`, `DoorAccessService`, `PersonnelDeviceAccessService`, `AccessWizard`, `DoorCatalog`, `PersonnelProfileService`, `PersonnelPhotoService`, `AccessAuditLogger`, `DeviceClientFactory`.

**Models:** `Door`, `PersonnelDoor`, `FingerprintEnrollment`, `AccessAuditLog`, `DeviceSyncEvent`, plus access relations on `Personnel`, `Terminal`, `AccessGroup`.

**Jobs/commands:** `EnrollFingerprintJob`, `ProcessAccessSyncJob`, `ExpireDoorGrants`.

**Views:** `resources/views/access/` — dashboard, user profile, editor, enrollment, doors, matrix, synchronization, audit, and the wizard steps.

**Configuration:** `config/access.php` — enrollment timeout/queue and synchronization batch limit.

**Routes:** all under `/access` with the `access.*` name prefix; the existing `/personnel`, device, event and attendance routes are unchanged.

---

## 6. Verification performed

| Check | Result |
|---|---|
| `php artisan test` (Unit + Feature) | **88 passed, 582 assertions** |
| `vendor/bin/pint --test app tests routes database config` | **PASS — 133 files** |
| HTTP smoke test on throwaway SQLite database | Login, dashboard, all access pages, profile, wizard, skip-enrollment, deploy, suspend/reactivate, grant/revoke, bulk sync, draft discard — **all expected responses, 0 HTTP 500s** |
| New expiry test | Expired grant removed, controller revocation queued, audit written, second run idempotent |
| Live hardware | **Not available / not tested** |

The test suite covers enrollment read-back confirmation, late cancellation, poor-quality and offline failures, least-privilege onboarding, matrix export, pending offline sync, unrelated assignee preservation, role protection, duplicate rejection, grant/revoke and expiry.

### One defect found and fixed during final verification

Bulk synchronization called `queueBatch()`, which used `->count()` on an array returned by `queueAssignments()`. This would have failed for **every** “sync all”/“sync selected” request. It is fixed (`count(...)`) and covered by the new command/queue test plus the HTTP bulk-sync smoke step.

---

## 7. Deployment steps

1. **Back up the database** and `.env` / `APP_KEY`.
2. Run `php artisan migrate --force`.
3. Run a queue worker (the enrollment and sync jobs are queued):
   `php artisan queue:work --tries=1 --timeout=960`
   Supervise it (systemd/Supervisor) and restart on deploy.
4. Ensure the scheduler runs: `* * * * * php artisan schedule:run`.
5. Open **Access → Doors & Controllers**, verify each imported door’s controller/channel, and run **Check fingerprint capability** on each terminal.
6. On one non-critical terminal, perform a real enrollment and confirm the slot appears in the device menu and the application marks it confirmed.
7. Review `config/access.php` timeouts against the site’s capture experience; `ACCESS_ENROLLMENT_TIMEOUT` must exceed the time a person needs at the scanner. If the fingerprint head is not on reader 1, set `ACCESS_ENROLLMENT_READERS` (comma-separated reader numbers).
8. Verify NTP/time zone on controllers; a skewed clock breaks event history and validity windows.
9. Confirm queue worker and scheduler are actually processing: the synchronization page should move writes from pending to confirmed.
10. Complete the local legal/privacy steps for biometric collection (notice, lawful basis/consent, retention, deletion request handling) before enrolling staff.

---

## 8. Known limitations and risks

1. **No live device acceptance test was possible here.** Firmware may not expose `FingerPrint/SetUp` even when a fingerprint reader is present; the capability probe and local-enrollment fallback handle that, but only a real terminal proves it.
2. **No template distribution.** A fingerprint captured on terminal A is not available on terminal B. This is a protocol/security limitation, not an unfinished synchronization feature. Door permissions still synchronize normally.
3. **No verified remote template deletion.** Removing a person or re-enrolling does not delete a template through an unverified endpoint. Local device menus remain the supported method for template removal. The UI does not claim otherwise.
4. **Per-door day/time schedules** are represented by device plan templates; they are not editable as day/hour grids in this application. Validity dates and grant expiry are enforced.
5. **Offline revocation is inherently delayed.** The UI labels it pending and warns that a controller may keep enforcing its last stored permissions until it reconnects. Recovery is the retry queue plus a manual sync; a controller that never returns requires on-site intervention.
6. **Transport security depends on device configuration.** Many controllers are configured for HTTP. Prefer HTTPS with `verify_ssl`, an isolated management VLAN/firewall allow-list, and unique device credentials. The application cannot make an HTTP-only terminal encrypted.
7. **Read-only access is available to all authenticated accounts** by design (`access.view`). Only administrators can enroll, grant, revoke, suspend, delete or change controllers. If the site requires read-only access to be admin-only, narrow the gate and sidebar visibility.
8. **Biometric-adjacent data still has retention obligations.** Audit logs, enrollment metadata and photos contain personal data even though templates do not leave the terminal. Define retention and deletion procedures.
9. **Legacy backfill creates doors from channel numbers.** Names/locations may need cleanup; permissions are not pushed by the migration, so devices are untouched until an administrator synchronizes.
10. **Shared administrator accounts weaken audit attribution.** The audit log records the signed-in account, not the human. Use individual accounts for accountability.

---

## 9. Operational summary

- New users: **Access → Add New User** → details → terminal → capture (or skip with documented reason) → doors → review → save.
- Existing users: open the profile to grant/revoke doors, suspend/reactivate, re-enroll a finger, synchronize, review history or delete.
- Suspended users keep their profile, grants and confirmed enrollment records, but every controller is asked to block them.
- A controller write is not “done” until the controller confirms it. The profile and synchronization page always show the difference.
- Expired per-door grants are cleaned up every five minutes by `access:expire-grants`; the resulting controller change follows the normal pending/retry path.
