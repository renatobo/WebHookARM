# Security Policy

## Supported Versions

Security fixes are provided only for the latest released major/minor line of **WebHookARM**. The repository currently has a **1.0.0** release.  [oai_citation:0‡GitHub](https://github.com/renatobo/WebHookARM)

| Version | Supported          |
| ------- | ------------------ |
| 1.0.x   | :white_check_mark: |
| < 1.0   | :x:                |

If you are running an older fork or a modified copy, update to the latest release on `main` before reporting.

## Reporting a Vulnerability

### Where to report (preferred)
Use GitHub’s private vulnerability reporting:

- Create a **private security advisory** here:
  - `https://github.com/renatobo/WebHookARM/security/advisories/new`

This keeps the report private while we triage and prepare a fix.

### If private reporting is not available
Open a GitHub issue **only for non-sensitive bugs** (no exploit details, no secrets):
- `https://github.com/renatobo/WebHookARM/issues`

If your report includes exploit steps, authentication bypass details, webhook endpoints, tokens, or user data, do **not** file a public issue.

### What to include
Provide enough detail to reproduce and validate the issue:

- Affected version(s) (plugin version and commit SHA if possible)
- WordPress version, PHP version, hosting environment
- ARMember version and whether the hook `arm_update_profile_external` is involved
- Exact configuration relevant to the bug (redact secrets)
- Steps to reproduce, expected vs actual behavior
- Proof-of-concept (PoC) where appropriate (keep it minimal and safe)
- Impact assessment (data exposure, auth bypass, privilege escalation, etc.)

### Response timeline
- **Acknowledgement:** within 72 hours
- **Triage update:** within 7 days (may be faster for critical issues)
- **Fix and release:** target depends on severity and complexity

### Coordinated disclosure
Please allow a reasonable time for a fix before public disclosure. If you need a coordinated disclosure date, propose one in the advisory.

### Scope notes (what we consider security-relevant here)
Examples of issues that qualify:

- Authentication bypass / secret-key validation weaknesses
- Unauthorized data exfiltration via webhook payloads
- CSRF, privilege escalation, or unsafe settings changes in the WP admin
- Injection issues (e.g., header/URL manipulation affecting outbound requests)
- Leakage of secrets (stored config, logs, debug output)

Non-qualifying (typically):
- Pure availability issues without security impact
- General misconfiguration of the user’s webhook endpoint (e.g., a public Google Apps Script URL) unless the plugin contributes to exposure

Thank you for reporting responsibly.
