# Device assignments and failed attempts

Implemented 29 October 2026. The additive migration preserves personnel, terminals and attendance records. Existing terminal links are retained as assignments with an unverified confirmation state; installing the migration does not grant or revoke physical access.

## Managing a person

Open Personnel, then View to see every configured API terminal, the assignment, and the device confirmation state. Edit lets an administrator change name, phone, user type, enabled status, department, date added, access start/expiry and selected devices. For example, Prince can be assigned to Main Entrance while Server Room remains unchecked. The list shows the number assigned out of the total; each Remote Access device card also links to its assigned people and failed attempts.

Dates are entered and displayed in Africa/Harare, stored in UTC, and sent in the configured device timezone. Date added is editable local metadata and does not alter the original created_at timestamp. Department is local metadata. Device-linked employee IDs remain fixed because fingerprints and event history depend on that identity. Existing legacy date values should be compared with the terminal before editing; they are not bulk-reinterpreted during migration. Imported profiles without explicit validity dates need access start and expiry dates set before they can be synchronized.

Device checkboxes select terminals. Door channels are the separate channel numbers inside each selected terminal (normally channel 1 on a single-door reader). Selecting a device does not copy biometric templates; enroll the person's fingerprint on that assigned device after saving their profile. Pre-save enrollment cannot grant access, and enrollment on an unassigned or inactive profile is rejected.

## Delivery and confirmation

Saving persists the latest intended permissions and queues an update for each API terminal. Assigned devices receive the profile and validity dates. Unselected devices receive a blacklist update when the identity exists; a verified absence also confirms no profile is present. Biometric templates are preserved during revocation. Disabling a person sets the device user type to blackList; disabling the ISAPI Valid.enable flag would instead disable expiry checks and is not used for this purpose.

Each update reads back the targeted employee record. A successful HTTP response alone is insufficient: name, phone, role, door channels/plans and validity dates must match. Revocations require the blocked type or verified absence. Unsupported firmware, malformed responses, rejected writes or stale readback stay pending with an explanation.

Keep `php artisan schedule:work` running for automatic retries. The queue retries with increasing delays up to 30 minutes and always uses the latest saved profile and assignment. Removing an assignment replaces any older queued grant. Retry existing assignments and bulk Sync Assignments never grant access to newly added devices. Imports preserve existing local profiles and assignment decisions. Per-person locks serialize profile writes and deletion against scheduled retries.

Pending revocation explicitly warns that access may remain until the terminal confirms. Confirmation describes the last successful device readback; it does not continuously audit subsequent edits made on the terminal itself. No live changes to people's permissions were made as part of implementation verification.

Removal archives the local identity immediately: the person is suspended, soft-deleted and hidden from the lists, while their history, audit entries and attendance records are retained. A deletion is queued for every controller that ever held the identity, its templates or a door grant, and already-confirmed removals clear older queued updates so they cannot recreate the profile. The request never waits on hardware, so an offline terminal cannot hold the browser open; the scheduler applies queued deletions as devices reconnect, with the same backoff as profile updates. Because the local record is archived before any device confirms, an offline terminal may keep enforcing the person until it reconnects, and each device's confirmation state shows what is still outstanding.

## Removing several people

Both the Personnel list and the Access list accept a selection when the signed-in account may delete people (Deactivate Personnel on Personnel, Access Management on Access). Tick row checkboxes, or the header checkbox to select everyone on the current page, then choose Delete selected and confirm. Selection is page-scoped: changing page, filter or search clears it, and the server never accepts a client-supplied "all" instruction.

A bulk removal is all-or-nothing. The server accepts up to 100 ids, takes each person's access lock, verifies that every affected controller is API-managed, and applies the archive, the queued device deletions and one audit entry per person in a single transaction. A stale list, a selection over 100, a person being synchronized, or a standalone reader in the selection means nothing is deleted and the page states why. Each audit entry records the employee number, the selection size and the number of queued device removals.

Bulk removal is written as a fixed number of statements rather than a set per person: a 100-person selection reads the affected relations once and upserts the assignments, queued deletions and audit entries in batches. Measured on the local SQLite suite, a 100-person removal costs 110 queries and about 13 ms of database time (117 ms wall), against 1,603 queries and 97 ms before batching.

## Failed attempts

The Failed Attempts page filters collected events by terminal and date/time. It displays the terminal and recorded timestamp even when the person is unknown. It never invents a name for an anonymous scan. PDF and document downloads retain the result filter. Currently recognized denial codes are major 5 with minor 2, 3, 22, 39, 40 or 76, or an explicit AccessDenied event. Other alarm codes are not automatically treated as failed identity attempts.

Only attempts actually recorded by the terminal and collected by the application can be shown. Firmware-specific denial codes still need comparison with real device payloads. Anonymous exit-button events remain separate from identified attendance logout.

## Verification and hardware status

The full dashboard suite passes at 129 tests / 848 assertions using isolated in-memory data and mocked terminal responses, including seven bulk-removal cases (78 assertions) covering page-scoped selection, rejected and stale selections, lock contention, atomic rollback, permissions and list rendering. Coverage includes four-device selection, date/phone updates, revocation, stale queued grants, rejected writes, stale readback, partial deletion, imports, enrollment restrictions, anonymous failed attempts and filtered exports. Browser checks covered the personnel list, editor, device shortcuts and failed-attempts page.

All four configured devices showed offline during the live UI check. Actual firmware acceptance and physical access enforcement still require verification when the terminal network is available. The application shows pending or unverified states until readback succeeds; it does not claim hardware confirmation from local saves.

Protocol reference: [Hikvision ISAPI access control documentation](https://open.hikvision.com/hardware/v2/08%E5%8D%8F%E8%AE%AE%E9%80%8F%E4%BC%A0/%E6%98%8E%E7%9C%B8%E9%97%A8%E7%A6%81.html). The local package DTO maps the app's blocklist enum to the documented blackList wire value. The installed path-package copy is updated too because this project installs the package without symlinks.
