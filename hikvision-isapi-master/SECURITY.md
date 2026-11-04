# Security Policy

## ZCHPC Access Control Suite

This package is maintained by the **Zimbabwe Centre for High Performance Computing (ZCHPC)**.
Website: [https://hpc.ac.zw](https://hpc.ac.zw) | Accelerating innovation through Supercomputing.

## Supported Versions

This project supports the latest stable release line only unless the maintainer explicitly states otherwise.

The current stable release is v1.5.4, so security fixes are targeted at the v1.5.x line.

| Version | Supported for security fixes |
| --- | --- |
| v1.5.x | Yes |
| < v1.5.x | No |

Older versions are unsupported for security fixes unless a maintainer publishes a separate support statement.

## Reporting a Vulnerability

Do not open public GitHub issues for suspected vulnerabilities.

Prefer GitHub private vulnerability reporting or GitHub Security Advisories if they are enabled for this repository. If those channels are unavailable, email the ZCHPC team at **security@hpc.ac.zw** with a clear subject such as `Security report: zchpc-access-control`.

Please include:

- Affected package version
- PHP and Laravel versions
- Minimal reproduction steps or proof of concept
- Expected and actual impact
- Relevant logs, with secrets and personal data removed
- Any suggested mitigation or patch details

The ZCHPC team will review reports and coordinate fixes and disclosure on a best-effort basis.

## Security Scope

This package is intended for legitimate access-control integrations and defensive or administrative use cases only.

Reports are in scope when they affect this package's code, configuration guidance, authentication handling, webhook handling, XML parsing, request signing or transport behavior, or documentation that could lead users to deploy insecurely.

Reports are out of scope when they only affect Hikvision device firmware, Hikvision cloud services, physical access-control policy, network architecture outside this package, or application code that consumes this package without a package-level vulnerability.

## Secure Usage Guidance

- Never commit device credentials, webhook credentials, API tokens, certificates, face images, biometric data, or raw personally identifiable data.
- Store credentials in environment variables (e.g. `ZCHPC_DEVICE_PASSWORD`) or a secret manager.
- Prefer HTTPS/TLS for device and webhook communication where supported.
- Enable SSL certificate verification in production (`ZCHPC_DEVICE_VERIFY_SSL=true`).
- Restrict device and webhook network access with firewalls, VPNs, private networks, or equivalent controls.
- Validate webhook payloads before trusting event data.
- Avoid logging credentials, tokens, face images, biometric data, or raw personally identifiable data.
- Treat XML input as untrusted and parse it defensively.
- Rotate device and webhook credentials after suspected compromise.

## Disclosure Policy

Please give the ZCHPC team a reasonable opportunity to investigate and release a fix before public disclosure. Coordinate publication timing through the private reporting channel whenever possible.

After a fix is available, users should upgrade to the supported v1.5.x release line and rotate any credentials that may have been exposed.
