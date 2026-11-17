# Current-personnel numbering correction

Production was checked after the numbering feedback: 58 active records, 2 unfinished drafts and 90 archived records. The current-personnel total is 60. The initial allocation incorrectly included archived records in the public numbering range.

The scope-correction migration clears that allocation and numbers only records without a deletion timestamp, in internal record order. Drafts are included. Archived records remain archived and retain their original internal device identifiers and history; no deleted identity is restored. New IDs continue after the corrected range, with the existing transactional allocator and collision checks. Numbers assigned to users archived after this correction remain reserved normally.

The reported step-3 failure was traced to a pristine draft whose legacy device ID was already occupied by a different enabled identity on Main Entrance. The enrollment guard remains enforced. For a draft with no enrollment history, terminal assignment, door grants or pending synchronization, the provisioner may use its own HPC identifier only after checking that it is unused locally and absent from the selected device. It creates and reads back a blocked identity before capture. It does not overwrite or disable the unrelated existing device identity. Capture then uses the refreshed device identifier.

Existing enrolled, assigned or otherwise unapproved identities cannot use this recovery path. Capture is still confirmed only by device readback; continuing without a fingerprint records deferral rather than false success.
