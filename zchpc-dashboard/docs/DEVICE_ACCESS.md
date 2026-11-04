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

Deletion retains the local record if any associated device cannot confirm removal. Already-confirmed removals clear old queued updates so they cannot recreate deleted device profiles. Retry deletion after connectivity is restored; for durable access removal while a device is offline, uncheck its assignment and save to queue revocation.

## Failed attempts

The Failed Attempts page filters collected events by terminal and date/time. It displays the terminal and recorded timestamp even when the person is unknown. It never invents a name for an anonymous scan. PDF and document downloads retain the result filter. Currently recognized denial codes are major 5 with minor 2, 3, 22, 39, 40 or 76, or an explicit AccessDenied event. Other alarm codes are not automatically treated as failed identity attempts.

Only attempts actually recorded by the terminal and collected by the application can be shown. Firmware-specific denial codes still need comparison with real device payloads. Anonymous exit-button events remain separate from identified attendance logout.

## Verification and hardware status

49 automated tests / 278 assertions passed using isolated in-memory data and mocked terminal responses. Coverage includes four-device selection, date/phone updates, revocation, stale queued grants, rejected writes, stale readback, partial deletion, imports, enrollment restrictions, anonymous failed attempts and filtered exports. Browser checks covered the personnel list, editor, device shortcuts and failed-attempts page.

All four configured devices showed offline during the live UI check. Actual firmware acceptance and physical access enforcement still require verification when the terminal network is available. The application shows pending or unverified states until readback succeeds; it does not claim hardware confirmation from local saves.

Protocol reference: [Hikvision ISAPI access control documentation](https://open.hikvision.com/hardware/v2/08%E5%8D%8F%E8%AE%AE%E9%80%8F%E4%BC%A0/%E6%98%8E%E7%9C%B8%E9%97%A8%E7%A6%81.html). The local package DTO maps the app's blocklist enum to the documented blackList wire value. The installed path-package copy is updated too because this project installs the package without symlinks.
