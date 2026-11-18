# Fingerprint synchronization

Approved transfers use a reviewed source terminal identity and the person's canonical destination identity. `FingerprintSyncService::queue` records identifiers only. `fingerprints:sync-pending` runs every minute with bounded work, per-person locks and retry backoff. Access → Synchronization shows pending, cancelled and confirmed transfers and lets administrators retry pending work.

The worker rechecks personnel status, effective door permission, terminal assignment, identity holds and confirmed destination profile. Both terminals must be online, and the source and destination names must match the approved personnel name. Active capture sessions defer copying. Revoked, expired, suspended or archived access cancels pending transfers.

The current adapter uses the terminals' `FingerPrintUpload` and `FingerPrint/SetUp` APIs for normal fingerprints on reader 1. Templates exist only in worker memory; the database and audit log store no template data or template hashes. Each copied slot must match an export from the destination. A request acknowledgement alone is insufficient. Different existing destination slots, duplicate fingerprints, unsupported exports and device failures remain pending without overwriting or deleting existing credentials. A person with no exportable source fingerprint must enroll before a transfer can finish.

This copies credentials to authorized doors; physical entry still depends on the controller, reader and door hardware. An offline terminal cannot receive a transfer. Pending work resumes when it can be verified, and identity holds require review.

Validation covers exact readback, idempotent completion, retained destination templates, offline retry, revocation cancellation and response-data redaction. The existing terminal lockout guard respects device-reported unlock timers across API clients rather than repeatedly authenticating during a lockout.
