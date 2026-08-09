# WordPress Plugin Security Assessment (Pre-hardening Baseline)

## Executive Summary

- Scope reviewed: `webhookarm.php`, `uninstall.php`, the bundled Apps Script receiver, documentation, packaging scripts, and GitHub Actions workflows.
- Review target: the 1.3.1 implementation before the hardening work on `codex/wp7-state-of-the-art-hardening`.
- Overall risk: **Medium**. No critical vulnerability, unauthenticated WordPress entry point, SQL injection, command execution, unsafe upload, or direct output-escaping issue was found. The main risks are broad profile-data forwarding, secret exposure through URLs/redirects, an SSRF-hardening gap, and unsafe behavior in the sample receiver.
- Finding counts: Critical 0, High 1, Medium 4, Low 2.

## Hardening Branch Status

- WPSEC-001 resolved: unauthenticated header and payload logging was removed.
- WPSEC-002 resolved: credential-like keys are recursively redacted, payload size is bounded, and a payload filter supports stricter site policies.
- WPSEC-003 resolved: the secret is no longer placed in the URL or transmitted; requests use timestamped HMAC signatures.
- WPSEC-004 resolved: delivery uses `wp_safe_remote_post()` with validated redirects and HTTPS-only configuration by default.
- WPSEC-005 resolved: spreadsheet formula markers are neutralized before appending rows.
- WPSEC-006 mitigated: the saved secret is no longer rendered back into the admin page DOM.
- WPSEC-007 resolved: the receiver returns generic public errors and logs only allowlisted error categories.

## Critical

No critical findings.

## High

### WPSEC-001 Bundled receiver logs secrets and profile data before authentication

- File: `assets/webhookarm_appscript.gs:27-32`
- Impact: anyone who reaches the public Apps Script endpoint can cause request headers and payloads, including the shared secret and personal data, to be retained in Apps Script execution logs.
- Evidence: `doPost()` logs `e.headers` and `e.postData.contents` before validating either secret source at lines 43-50. The documentation instructs users to deploy the receiver with access set to `Anyone`.
- Remediation: remove request-header and payload logging. If diagnostic logging is needed, place a redacted, opt-in log after authentication and never log the secret or complete profile payload.

## Medium

### WPSEC-002 The complete ARMember form payload is exported without an allowlist

- File: `webhookarm.php:120-145`
- Impact: password-like fields, private profile metadata, or future ARMember fields may be sent to the configured third party without the site owner realizing their sensitivity.
- Evidence: every key in `$form_data` is copied into `$payload`; the code then adds three identity fields and serializes the whole array. There is no field allowlist, denylist, size limit, or filter specifically intended to control disclosure.
- Remediation: define a conservative default allowlist, explicitly exclude credential/password/token fields, document the transmitted schema, and add a narrowly named filter so administrators can intentionally extend the field set.

### WPSEC-003 Shared secret is placed in the URL and can cross redirects

- File: `webhookarm.php:127-146`
- Impact: URL query strings are commonly retained in proxy, CDN, server, analytics, and troubleshooting logs. With up to five redirects enabled, the secret-bearing URL and custom authentication header may also be sent beyond the originally configured endpoint.
- Evidence: the secret is added as `?key=...`, duplicated in `X-Security-Key`, and the request allows five redirects.
- Remediation: authenticate only with a header (preferably an HMAC signature with timestamp and body digest), default redirects to zero, and require an explicit compatibility mode for receivers that can only read a query parameter.

### WPSEC-004 Arbitrary configured destinations use the non-safe HTTP API

- File: `webhookarm.php:135-147`
- Impact: a privileged configuration mistake or compromised administrator session can cause WordPress to POST profile data to loopback, link-local, or private-network services; redirects expand that reach.
- Evidence: the administrator-controlled URL is passed to `wp_remote_post()`. WordPress documents `wp_safe_remote_post()` as the variant that validates the original URL and every redirect to reduce SSRF risk.
- Remediation: use `wp_safe_remote_post()` and reject non-HTTPS destinations by default. If private/local endpoints are an intentional feature, require a documented opt-in filter rather than permitting them by default.

### WPSEC-005 Spreadsheet formula injection is not neutralized

- File: `assets/webhookarm_appscript.gs:96-104`
- Impact: attacker-influenced profile values beginning with spreadsheet formula markers can be interpreted as formulas when appended to a sheet, potentially enabling external requests or misleading sheet operators.
- Evidence: `user_id`, `user_login`, and `user_email` are passed directly to `appendRow()` without neutralizing leading `=`, `+`, `-`, or `@` characters.
- Remediation: coerce expected numeric fields to numbers and prefix formula-like strings with an apostrophe before `appendRow()`. Apply the same rule to any future columns derived from ARMember fields.

## Low

### WPSEC-006 Secret remains readable and is rendered back into the admin form

- File: `webhookarm.php:102-104, 311-313, 465-473`
- Impact: the shared secret is stored as a normal WordPress option and inserted into the page DOM for any administrator viewing the screen; it can be exposed through database backups, admin-browser extensions, or DOM inspection.
- Evidence: `get_option()` returns the plaintext secret and the full value is rendered into the password input.
- Remediation: avoid re-rendering an existing secret; show a blank replacement field plus a “secret configured” state. Consider supporting a constant/environment-provided secret for higher-security deployments. Reversible encryption within the same WordPress database offers limited benefit and should not be presented as strong protection.

### WPSEC-007 Receiver returns internal exception messages

- File: `assets/webhookarm_appscript.gs:107-109`
- Impact: runtime errors can reveal implementation or configuration details to unauthenticated callers.
- Evidence: the catch block returns `err.message` in the public response.
- Remediation: return a generic error string and, if needed, record only a redacted internal diagnostic after authentication.

## Positive Controls Observed

- Direct PHP entry is guarded in both runtime and uninstall paths.
- The settings page requires `manage_options`; Settings API submission supplies capability and nonce enforcement.
- All three options have explicit sanitization callbacks and retain their existing option keys.
- Admin output is generally escaped for its HTML context.
- No REST routes, AJAX handlers, raw SQL, dynamic includes, deserialization, filesystem writes, or shell execution are present.
- Production logs do not include the WordPress payload or secret; logging is limited to user ID and response/error details when `WP_DEBUG` is enabled.

## Notes

- This was a static source review, not a penetration test or live WordPress/ARMember integration test.
- The intended administrator-controlled webhook destination is inherently an outbound data-export feature. WPSEC-004 is therefore defense in depth, while WPSEC-002 is primarily about making that export bounded and predictable.
- The Apps Script receiver also has reliability defects outside strict vulnerability triage: documentation names `AUTH_SECRET` and `SHEET_NAME`, while code reads `WA_AUTH_SECRET` and `WA_SHEET_NAME`; its attempted `setResponseCode()` call is not supported by Apps Script `TextOutput`.
