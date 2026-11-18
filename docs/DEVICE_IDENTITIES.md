# Additional device identities

Some people have multiple historical employee numbers on a controller. An explicitly reviewed `personnel_device_aliases` row links each additional number to the existing personnel record, scoped to that terminal. It does not create a person, renumber the device, transfer fingerprints or grant access. Canonical public HPC numbers remain unchanged.

Event imports normalize the linked employee number to the existing personnel identity. The original device number remains in the original event payload; the event key and timestamp are preserved. Linking also updates the normalized reference on already imported events for that terminal. Reports and attendance therefore use the existing full name and public HPC number.

Personnel import resolves aliases before creating records and never restores archived people. Explicit `personnel_device_exclusions` skip operator-excluded IDs during imports without creating personnel records or changing physical device access. Historical events remain visible. Reconsidering an exclusion requires an explicit operator review.

Profile updates, authorized access changes and durable removal retries cover both the canonical ID and its confirmed aliases. A partial device failure retains the retry until every linked identity confirms the requested state. Alias IDs remain reserved against creation of duplicate personnel and automatic HPC allocation. Terminal deletion is blocked while reviewed links or exclusions exist.

Regression coverage: original payload preservation, terminal-scoped resolution, historical/new events, archived identity protection, import exclusions, duplicate prevention, revocation without fingerprint changes and removal of all aliases.
