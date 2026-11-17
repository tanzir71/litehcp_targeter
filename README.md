# LiteHCP Targeter

**Self-hosted HCP targeting & segmentation in one PHP file.**

Import HCP lists from any CSV, fill and flag missing data, enrich with rules, build consent-safe audiences in plain language, plan campaign ROI, and export lists ready for your CRM or email platform — on your server, with SQLite. No vendor cloud, no per-seat fees, offline after setup. Optimized for shared hosting (cPanel / Namecheap).

Current version: **1.2.1** — see [What's new in 1.2](#whats-new-in-12) and [CHANGELOG.md](CHANGELOG.md).

## Links

- Site & docs: https://tanzir71.github.io/litehcp_targeter/
- Live demo (no install): https://tanzir71.github.io/litehcp_targeter/demo.html
- Setup: [SETUP.md](SETUP.md) · Security: [SECURITY.md](SECURITY.md)
- Comparison vs. enterprise platforms: https://tanzir71.github.io/litehcp_targeter/compare.html

## Quick start

1. Upload `litehcp.php` to `public_html/` (PHP 8.0+, 8.3 or newer recommended; HTTPS enforced).
2. Visit `/litehcp.php` and register — the first user becomes admin.
3. **Overview → Load sample data** loads the embedded demo dataset in one click: **200 realistic HCP profiles, 9 enrichment rules, 6 audiences**. The Overview then walks you through five steps to your first export.
4. Import your own CSVs: upload → match columns → check data → import.
5. Optional: `php litehcp.php action=selftest` checks the install against a throwaway database.

Run locally instead:

```bash
git clone https://github.com/tanzir71/litehcp_targeter
cd litehcp_targeter
php -S localhost:8000   # → http://localhost:8000/litehcp.php
```

## What it does

| Stage | Capability |
|---|---|
| Import | Any CSV layout; columns recognized by name (NPI, First name, Email opt-in…); every row checked before anything is written (missing or duplicate IDs, NPI check digit, unreadable dates, unknown consent values); background chunked import with progress; safe re-imports (upsert by HCP ID) |
| Impute | Per-field strategies (fixed / mode / mean / leave-null-and-flag), offered only for columns with gaps; imputed values visibly marked |
| Score | Priority score (consent + recency + engagement, configurable weights), tier A/B/C, decile 1–10, data-confidence score (0–100); every profile explains its score point by point and rule by rule |
| Enrich | When/Then rule builder (or JSON) for personas, tags, contact clearance and score changes; reorderable run order; test on your data before saving |
| Audiences | Plain-language builder (specialty, region, tier, reachability, decile, confidence, activity, tags, rules, custom fields) with a live count after every condition; filters saved in 1.1 keep working |
| Plan | Contacts, responses, cost, value and ROI per audience, plus planned calls per tier; plans saved and audited |
| Export | Minimal CSV, CRM target list (NPI, tier, planned calls), email platform list (consented HCPs only, admin + PII setting) — every export audited |

## Privacy & security defaults

- **Suppression can't be bypassed.** HCPs on the suppression list or with the *Do Not Contact* persona are excluded inside every count, preview and export query, for every role. IDs can be suppressed before they are imported.
- Exports contain **no email/name** unless an admin enables `export_pii`, and then only in the email platform format, only for HCPs with email consent (audited).
- Roles: **viewer** (look), **editor** (import, build, export), **admin** (suppression, settings, people).
- All data lives in `litehcp.db` (SQLite) next to the app — nothing leaves your server. The app loads no third-party fonts or scripts.
- CSRF on every POST, nonce-based CSP, rate limiting, prepared statements, allowlisted audience fields, upload validation, append-only audit log. Details in [SECURITY.md](SECURITY.md).

## What's new in 1.2

| Area | 1.1 | 1.2 |
|---|---|---|
| Suppression | A rule could tag Do Not Contact, but exports didn't check it | Excluded inside every count, preview and export; suppression list for manual IDs |
| Audiences | Hand-typed SQL-like filters | Point-and-click builder with live counts |
| Imports | Map by hand, find problems after | Upload → match → check → import, with automatic column recognition |
| Prioritization | 0–100 score | Tiers, deciles, planned calls, explainable scores |
| Exports | One minimal CSV + PII switch | Minimal, CRM target list, email platform presets |
| Rules | JSON in text boxes | When/Then builder, run order, test before save |
| Team access | admin / user | viewer / editor / admin |
| First run | Sample import you had to run | One click to 200 HCPs, 9 rules, 6 audiences |

### Upgrading from 1.1

1. Back up `litehcp.db`.
2. Replace `litehcp.php` and open it. The database migrates itself on first load (new columns, suppression list, tiers and deciles).
3. Users with the old `user` role become **editors**. Audiences saved as filters keep working.
4. Run `php litehcp.php action=selftest`.

One intentional change: exports now leave out *Do Not Contact* HCPs, so the 1.1 sample segment "Suppression list (Do Not Contact)" exports no rows. Review those HCPs on the **Suppression** page instead.

## Repository layout

```
litehcp.php       # the entire application
index.html        # landing page (GitHub Pages), incl. What's new in 1.2
docs.html         # documentation
compare.html      # comparison vs. enterprise HCP platforms
demo.html         # the 1.2 app running in the browser on sample data (no server)
*-alternative.html  # Veeva, Salesforce and IQVIA alternative guides
sitemap.xml, robots.txt, llms.txt
SETUP.md, SECURITY.md, CHANGELOG.md
```
