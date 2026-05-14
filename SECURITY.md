# SECURITY — LiteHCP Targeter

This document summarizes security hardening applied in this repository and recommended production practices.

## Security fixes applied
- **SQL injection hardening**: all database writes/reads use **PDO prepared statements** with bound parameters; dynamic SQL uses strict allowlists for identifiers.
- **XSS prevention**: centralized output escaping helper `htmlEscape()` is used for all user-reflecting UI outputs.
- **CSRF protection**: every POST form includes a CSRF token and the server rejects invalid/missing tokens (`400 Bad Request`).
- **Authentication hardening**:
  - Passwords stored with `password_hash()` and verified with `password_verify()`.
  - `session_regenerate_id(true)` on successful login.
  - Idle session timeout enforcement.
- **Login/critical rate limiting**: SQLite-backed per-IP limits return `429 Too Many Requests` with `Retry-After`.
- **File upload safeguards**:
  - CSV-only upload (extension + MIME checks), size limit, sanitized filename.
  - Upload directory has `.htaccess` deny + `index.html` guard.
- **Error handling**:
  - No stack traces are sent to the browser.
  - Exceptions/errors are logged server-side with short error IDs.
- **Security headers**:
  - CSP (nonce-based inline scripts), X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy.

## Key rotation & secrets
Secrets are loaded from `.env` (not committed). To rotate:
1. Update `CRON_TOKEN` in `.env`.
2. Update your cron job line to use the new token.
3. If you suspect compromise, rotate admin password and invalidate sessions (log out all users).

## Logging controls
- Logs are written to `litehcp_uploads/litehcp.log` and `litehcp_uploads/litehcp_error.log`.
- To reduce logging, you can adjust the code paths that call `log_line()` / `error_line()`.
- Keep `litehcp_uploads/` access-denied (Apache uses `.htaccess`; for Nginx add an explicit deny rule).

## Production hardening checklist
- **TLS**: enforce HTTPS only (HSTS recommended once stable).
- **Access control**: restrict `/litehcp.php` behind Basic Auth or IP allowlist where possible.
- **WAF**: enable hosting WAF rules if available (rate limiting at the edge).
- **Database**: for multi-user / higher traffic, migrate from SQLite to Postgres/MySQL with proper backups.
- **Backups**: snapshot `litehcp.db` regularly and test restore.

## Notes about CSP
The default CSP allows Bootstrap (jsdelivr) + Google Fonts and uses a per-request nonce for inline scripts.
If you host assets locally, tighten CSP by removing external domains.
