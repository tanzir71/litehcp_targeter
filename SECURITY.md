# SECURITY — LiteHCP Targeter

This document summarizes security hardening applied in this repository and recommended production practices.

## Security fixes applied
- **SQL injection hardening**: all database writes/reads use **PDO prepared statements** with bound parameters; dynamic SQL uses strict allowlists for identifiers.
- **Audience builder**: audience definitions are validated against a fixed allowlist of fields and operators; every value is quoted with `PDO::quote()` or cast to a number before it reaches SQL. No user-written SQL runs. Filters saved by 1.1 are re-validated on every use.
- **XSS prevention**: centralized output escaping helper `htmlEscape()` is used for all user-reflecting UI outputs; JSON embedded in pages is encoded with `JSON_HEX_TAG | JSON_HEX_AMP`.
- **CSRF protection**: every POST form includes a CSRF token and the server rejects invalid/missing tokens (`400 Bad Request`). The JSON endpoints used by the builders and the import runner are POST-only and require the token in an `X-CSRF-Token` header.
- **Authentication hardening**:
  - Passwords stored with `password_hash()` and verified with `password_verify()`.
  - `session_regenerate_id(true)` on successful login.
  - Idle session timeout enforcement.
  - The user's role is re-read from the database on every request, so role changes and removed accounts take effect immediately.
- **Roles**: viewer, editor and admin. Imports, rule and audience changes and exports need editor; suppression, settings, people and PII exports need admin. Checks are enforced server-side on every action, not just hidden in the UI.
- **Login/critical rate limiting**: SQLite-backed per-IP limits return `429 Too Many Requests` with `Retry-After`.
- **File upload safeguards**:
  - CSV-only upload (extension + MIME checks), size limit, sanitized filename.
  - Upload directory has `.htaccess` deny + `index.html` guard.
- **Export safeguards**:
  - Suppression (Do Not Contact persona or suppression list) is applied inside every export query and cannot be turned off.
  - Names and emails only appear in the email platform format, which needs an admin, the `export_pii` setting, and email consent per HCP.
  - Cells that start with `=`, `+`, `-`, `@` (and are not numbers) are prefixed with `'` to prevent spreadsheet formula injection.
  - Every export is written to the audit log with format, row count and excluded count; files are not stored on the server.
- **Cron**: the token is compared with `hash_equals()` and the default `CHANGE_ME` token is refused.
- **Error handling**:
  - No stack traces are sent to the browser.
  - Exceptions/errors are logged server-side with short error IDs.
- **Security headers**:
  - CSP (nonce-based inline script, `font-src 'self'`, no third-party origins), X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy.

## Key rotation & secrets
Secrets are loaded from `.env` (not committed). To rotate:
1. Update `CRON_TOKEN` in `.env`.
2. Update your cron job line to use the new token.
3. If you suspect compromise, rotate admin password and invalidate sessions (log out all users).

## Logging controls
- Logs are written to `litehcp_uploads/litehcp.log` and `litehcp_uploads/litehcp_error.log`.
- To reduce logging, you can adjust the code paths that call `log_line()` / `error_line()`.
- Keep `litehcp_uploads/` access-denied (Apache uses `.htaccess`; for Nginx add an explicit deny rule).

## Verifying an install
`php litehcp.php action=selftest` runs 33 checks against an in-memory database, including that no export in any format can contain a suppressed HCP, that builder values are quoted, that unknown fields and multi-statement filters are rejected, and that the role matrix holds.

## Production hardening checklist
- **PHP**: run 8.3 or newer; versions below 8.2 no longer receive security fixes.
- **TLS**: enforce HTTPS only (HSTS recommended once stable).
- **Access control**: restrict `/litehcp.php` behind Basic Auth or IP allowlist where possible; keep registration closed and add people from Settings.
- **WAF**: enable hosting WAF rules if available (rate limiting at the edge).
- **Database**: for multi-user / higher traffic, migrate from SQLite to Postgres/MySQL with proper backups.
- **Backups**: snapshot `litehcp.db` regularly and test restore.

## Notes about CSP
Since 1.2 the app loads no external assets (system font stack, inline styles, one nonce'd script), so the CSP allows only `'self'`. If you add your own hosted assets, add their origins explicitly.
