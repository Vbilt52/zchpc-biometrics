# Fingerprint synchronization

Approved transfers use a reviewed source terminal identity and the person's canonical destination identity. `FingerprintSyncService::queue` records identifiers only. `fingerprints:sync-pending` runs every minute with bounded work, per-person locks and retry backoff. Access → Synchronization shows pending, cancelled and confirmed transfers and lets administrators retry pending work.

The worker rechecks personnel status, effective door permission, terminal assignment, identity holds and confirmed destination profile. Both terminals must be online, and the source and destination names must match the approved personnel name. Active capture sessions defer copying. Revoked, expired, suspended or archived access cancels pending transfers.

The current adapter uses the terminals' `FingerPrintUpload` and `FingerPrint/SetUp` APIs on reader 1. It accepts documented legacy fingerprint types and `NoFP` empty responses. New copies use ordinary access (`normalFP`); source alarm-dismissal, duress or super-user privileges are not propagated, and existing destination credential settings are retained. Templates exist only in worker memory; the database and audit log store no template data or template hashes. Each copied slot must match an export from the destination. A request acknowledgement alone is insufficient. Matching destination fingerprints are reused regardless of their slot number. New copies use a free slot, retaining all occupied slots. Full destinations, fingerprints already bound to another identity, unsupported exports and device failures remain pending without overwriting or deleting existing credentials. A person with no exportable source fingerprint must enroll before a transfer can finish.

Protocol reference: [Hikvision ISAPI access-control documentation, fingerprint query and configuration](https://open.hikvision.com/hardware/v2/08%E5%8D%8F%E8%AE%AE%E9%80%8F%E4%BC%A0/%E6%98%8E%E7%9C%B8%E9%97%A8%E7%A6%81.html).

This copies credentials to authorized doors; physical entry still depends on the controller, reader and door hardware. An offline terminal cannot receive a transfer. Pending work resumes when it can be verified, and identity holds require review.

Validation covers exact readback, idempotent completion, retained destination templates, offline retry, revocation cancellation and response-data redaction. The existing terminal lockout guard respects device-reported unlock timers across API clients rather than repeatedly authenticating during a lockout.
