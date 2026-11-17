# SETUP — LiteHCP Targeter (Namecheap / cPanel)

## Requirements
- PHP 8.0+ with PDO + SQLite enabled. **PHP 8.3 or newer is recommended**; older versions no longer receive security fixes, and admins see a warning in the app.
- Write access in the app directory to create `litehcp.db` and `litehcp_uploads/`.

## Deployment (shared hosting)
1. Upload `litehcp.php` to `public_html/` (or a subfolder).
2. Upload docs if desired (`README.md`, `SECURITY.md`, `SETUP.md`, `index.html`).
3. In cPanel → **MultiPHP Manager**, set the directory to **PHP 8.3+** (8.0 minimum).
4. Set permissions:
   - `litehcp.php`: `0644`
   - app folder: `0755`
   - if created: `litehcp_uploads/`: `0750` (or `0755` if hosting requires it)
5. Enforce HTTPS:
   - Use your hosting panel or a redirect rule; do not run the app over plain HTTP.
6. Visit `/litehcp.php` and register the first user (automatically becomes **admin**).
7. On the Overview, click **Load sample data** to explore with 200 HCPs, 9 rules and 6 audiences, or go to **Imports** to bring in your own CSV.

## Check the install
Run the self-test from SSH or a cPanel terminal. It uses an in-memory database and a temporary folder, so it never touches your data:

```bash
php /home/USER/public_html/litehcp.php action=selftest
```

It prints one line per check and ends with `All 43 checks passed.` (exit code 0) or a list of failures (exit code 1).

## Upgrading from 1.1
1. Back up `litehcp.db` (and `litehcp_uploads/` if you keep logs).
2. Replace `litehcp.php` with the new file.
3. Open the app. The database migrates itself on first load: new columns, the suppression list, tiers and deciles. Users with the old `user` role become **editors**.
4. Run the self-test.

Behavior change: exports now always leave out HCPs with the *Do Not Contact* persona or on the suppression list. A 1.1 audience built to list those HCPs will export no rows; use the **Suppression** page instead.

## People and roles
- **Viewer**: sees the Overview, HCPs, audiences and rules; can't import, change or export.
- **Editor**: imports, builds rules and audiences, plans and exports (not names and emails).
- **Admin**: everything, plus suppression, settings, people, and email platform exports when PII export is on.

New self-registered accounts get the viewer role. Registration is closed by default; admins add people under **Settings → People and roles**.

## Database location
- The app uses SQLite at `sqlite:./litehcp.db` (next to `litehcp.php`).
- The schema is created on first load and migrated automatically (tracked in `PRAGMA user_version`).

## Environment configuration (.env)
Create a `.env` file next to `litehcp.php` (do not commit it):

```ini
CRON_TOKEN="change-this-long-random-token"
ACCENT_COLOR="#2540FF"
SESSION_TIMEOUT_SECONDS="1800"
UPLOAD_MAX_BYTES="5242880"
```

## Recommended cron (optional)
Use cron to recompute scores, re-apply rules and refresh tiers and deciles for all HCPs (keeps recency current; useful for large datasets).

```bash
php /home/USER/public_html/litehcp.php action=cron_recompute token=change-this-long-random-token
```

Notes:
- Replace `/home/USER/public_html/` with your actual path from cPanel. **Settings** shows the exact line for your server.
- The default token `CHANGE_ME` is refused; set `CRON_TOKEN` in `.env` first.
- Keep the token secret. Rotate it if leaked.

## Backups (recommended)
- Backup `litehcp.db` regularly (daily/weekly).
- Also backup `litehcp_uploads/` if you want to retain server logs.
