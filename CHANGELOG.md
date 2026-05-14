# CHANGELOG

## 2026-05-14
- Security hardening: CSP + security headers, CSRF on POST actions, server-side error logging (no stack traces), login/register/export rate limiting.
- SQL injection hardening: enforced prepared statements; tightened dynamic SQL to allowlist-based filters.
- Upload hardening: CSV-only validation (size/type), sanitized filenames, uploads directory access denial.
- UX: added footer links to SETUP/SECURITY docs and added a minimal landing page.
