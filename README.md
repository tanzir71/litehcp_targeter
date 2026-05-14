# LiteHCP Targeter

LiteHCP Targeter is a portable, single-file PHP 8+ + SQLite application optimized for shared hosting (cPanel / Namecheap). It supports CSV import with flexible mapping + imputation, JSON rules enrichment, segmentation, targeting simulation, and CSV export (privacy-first by default).

## Links
- Repo: https://github.com/tanzir71/litehcp_targeter
- Setup: https://github.com/tanzir71/litehcp_targeter/blob/main/SETUP.md
- Security: https://github.com/tanzir71/litehcp_targeter/blob/main/SECURITY.md

## Quick start
1. Upload `litehcp.php` to `public_html/`.
2. Ensure PHP 8+ is enabled.
3. Visit `/litehcp.php` and register the first user (becomes admin).
4. Use **Dashboard → Load sample** to import the embedded sample dataset.

## Privacy defaults
- Segment export defaults to minimal fields (no email).
- Admins can enable PII export in Settings; PII exports are audited.
