# LiteHCP Targeter

**Self-hosted HCP targeting & segmentation in one PHP file.**

Import HCP lists from CSV, normalize and impute missing data, enrich with JSON rules, build compliant segments, simulate campaign ROI, and export privacy-first lists — on your server, with SQLite. No vendor cloud, no per-seat fees, offline after setup. Optimized for shared hosting (cPanel / Namecheap).

## Links

- Site & docs: https://tanzir71.github.io/litehcp_targeter/
- Live demo (no install): https://tanzir71.github.io/litehcp_targeter/demo.html
- Setup: [SETUP.md](SETUP.md) · Security: [SECURITY.md](SECURITY.md)
- Comparison vs. enterprise platforms: https://tanzir71.github.io/litehcp_targeter/compare.html

## Quick start

1. Upload `litehcp.php` to `public_html/` (PHP 8+, HTTPS enforced).
2. Visit `/litehcp.php` and register — the first user becomes admin.
3. **Dashboard → Load sample** imports the embedded demo dataset: **200 realistic HCP profiles, 8 enrichment rules, 5 segments** — the whole pipeline is explorable immediately.
4. Import your own CSVs the same way: upload → map columns → run.

Run locally instead:

```bash
git clone https://github.com/tanzir71/litehcp_targeter
cd litehcp_targeter
php -S localhost:8000   # → http://localhost:8000/litehcp.php
```

## What it does

| Stage | Capability |
|---|---|
| Import | Any CSV layout; visual column mapping; chunked processing; safe re-imports (upsert by `hcp_id`) |
| Impute | Per-field strategies (fixed / mode / mean / leave-null-and-flag); imputed values visibly marked |
| Score | Priority score (consent + recency + engagement, configurable weights) and data-confidence score (0–100) |
| Enrich | Priority-ordered JSON rules: personas, tags, compliance flags, score deltas; built-in rule tester |
| Segment | Allowlist-validated SQL-like filters or rule-trace matching; strict (confidence-gated) and inclusive modes |
| Simulate | Contacts, responses, cost, value, ROI per segment — every run audited |
| Export | Minimal fields by default; PII export is admin-gated and individually audited |

## Privacy & security defaults

- Segment exports contain **no email/name** unless an admin enables `export_pii` (audited).
- All data lives in `litehcp.db` (SQLite) next to the app — nothing leaves your server.
- CSRF on every POST, nonce-based CSP, rate limiting, prepared statements, upload validation, append-only audit log. Details in [SECURITY.md](SECURITY.md).

## Repository layout

```
litehcp.php       # the entire application
index.html        # landing page (GitHub Pages)
docs.html         # documentation
compare.html      # comparison vs. enterprise HCP platforms
demo.html         # client-side interactive demo
*.html            # SEO alternative pages
sitemap.xml, robots.txt, llms.txt
SETUP.md, SECURITY.md, CHANGELOG.md
```
