# Fingerprint capture and storage verification

The terminal capture prompt confirms a sample was read. Enrollment is confirmed only after SetUp accepts storage and a readback finds the requested slot on the requested employee.

Reader status takes precedence over a general successful request acknowledgement. Status 1 accepts storage; 2 means the module is offline; 3 means retry/poor quality; 4 means full memory; 5 means a duplicate fingerprint; 7 means invalid slot; 8 means configuration is not required/supported by the module; 10 means outdated reader firmware. A duplicate never authorizes deleting or reassigning another identity's fingerprint.

If capture succeeds but enrollment reports a duplicate, use a different finger or review the existing enrollment with an authorized administrator. Choosing another slot alone does not resolve an already registered fingerprint. No biometric template is retained in application logs or enrollment records.

Protocol reference: https://open.hikvision.com/hardware/v2/08%E5%8D%8F%E8%AE%AE%E9%80%8F%E4%BC%A0/%E6%98%8E%E7%9C%B8%E9%97%A8%E7%A6%81.html (sections 30–31).

Regression coverage includes captured-template success with readback, accepted capture without storage, duplicate rejection despite a general success acknowledgement, mixed reader results, no-fingerprint responses, cancellation, and protected identity provisioning.
