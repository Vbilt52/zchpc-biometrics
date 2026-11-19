# Device connectivity and transport

Wi-Fi connectivity, a listening TCP port and an authenticated API response are separate checks. The dashboard reports a terminal online only after a successful authenticated check; stale checks, authentication errors and unreachable devices remain distinct.

Changing between ports 80 and 800 does not repair a missing network route. Use the port configured on that specific terminal. HTTPS requires a working TLS listener and a certificate trusted by the application host; changing only the application URL to `https` cannot enable TLS on a device. The inspected terminals currently answer their configured HTTP endpoints and reject TCP connections on port 443. No automatic HTTP/HTTPS downgrade or unverified certificate workaround is introduced.

The web application is served over HTTPS. Device HTTP traffic is a separate connection and is not encrypted by the website certificate. Enable supported device HTTPS and install a trusted certificate before changing a terminal's transport settings. Confirm authenticated API access using certificate verification, then verify profile writes, fingerprint readback and event collection on the selected port.

An API account lock is not a Wi-Fi outage. Shared client guards honor the terminal's reported unlock time and pause requests across workers. Firmware that returns an empty JSON authentication failure is checked once through its read-only XML device-info endpoint for lock status. The failed mutation is never replayed by that diagnostic.

Offline permission changes remain durable requests. They are confirmed only after the terminal returns the intended state. Fingerprint tasks also require an online source, an online destination, current door authorization and matching destination readback. Identity conflicts stay on hold for review. No software bypass can deliver data to a disconnected device.

For an unreachable terminal, confirm power, Wi-Fi association, current IP and port, routing from the VM and any network access restrictions. Once reachable, authenticate, check identity ownership before provisioning, allow queued retries and inspect the collection checkpoint. Do not restore previously archived personnel merely because an offline terminal still contains them.
