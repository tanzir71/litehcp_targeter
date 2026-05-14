# SETUP — LiteHCP Targeter (Namecheap / cPanel)

## Requirements
- PHP 8.0+ with PDO + SQLite enabled.
- Write access in the app directory to create `litehcp.db` and `litehcp_uploads/`.

## Deployment (shared hosting)
1. Upload `litehcp.php` to `public_html/` (or a subfolder).
2. Upload docs if desired (`README.md`, `SECURITY.md`, `SETUP.md`, `index.html`).
3. In cPanel → **MultiPHP Manager**, set the directory to **PHP 8+**.
4. Set permissions:
   - `litehcp.php`: `0644`
   - app folder: `0755`
   - if created: `litehcp_uploads/`: `0750` (or `0755` if hosting requires it)
5. Enforce HTTPS:
   - Use your hosting panel or a redirect rule; do not run the app over plain HTTP.
6. Visit `/litehcp.php` and register the first user (automatically becomes **admin**).

## Database location
- The app uses SQLite at `sqlite:./litehcp.db` (next to `litehcp.php`).
- SQLite schema is auto-created on first load.

## Environment configuration (.env)
Create a `.env` file next to `litehcp.php` (do not commit it):

```ini
CRON_TOKEN="change-this-long-random-token"
ACCENT_COLOR="#1A73E8"
SESSION_TIMEOUT_SECONDS="1800"
UPLOAD_MAX_BYTES="5242880"
```

## Recommended cron (optional)
Use cron to recompute scoring + re-apply rules for all profiles (useful for large datasets).

```bash
php /home/USER/public_html/litehcp.php action=cron_recompute token=change-this-long-random-token
```

Notes:
- Replace `/home/USER/public_html/` with your actual path from cPanel.
- Keep the token secret. Rotate it if leaked.

## Backups (recommended)
- Backup `litehcp.db` regularly (daily/weekly).
- Also backup `litehcp_uploads/` if you want to retain server logs.
