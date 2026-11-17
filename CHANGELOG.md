# CHANGELOG

## 1.2.0 — 2026-11-17

Rebuilt around how targeting teams work. Still one PHP file; upgrades in place.

**Compliance**
- Global suppression: HCPs with a suppression persona (default *Do Not Contact*) or on the new suppression list are excluded inside every audience count, preview and export query. There is no per-audience or per-role override. *Behavior change:* 1.1 exports did not apply this.
- Suppression page: paste HCP IDs with a reason (opted out, AMA PDRP restriction, deceased or retired, legal hold, other). IDs not yet imported are kept and excluded on arrival.
- Email platform exports only include HCPs with email consent and an address.

**Audiences**
- Point-and-click audience builder replaces hand-typed filters: specialty, region, role, organization, persona, tier, reachable by, priority score/decile, data confidence, engagement, last activity, tags, enrichment rules and custom fields. Live funnel (all → matching → excluded → ready), per-condition counts, tier mix and a top-10 preview.
- Values are validated against a field/operator allowlist and quoted with `PDO::quote()`; no user-written SQL runs. Filters saved in 1.1 keep working (re-validated).
- Campaign planner saved per audience (contact rate, response rate, cost, value, ROI) with planned calls per tier. The old simulator URL redirects here.
- Export presets: Minimal CSV, CRM target list (`hcp_id, npi, tier, planned_calls, …`), Email platform (admin + PII setting). Exports history page; spreadsheet formula injection neutralized.

**Imports**
- Four-step wizard: Upload → Match columns → Check data → Import.
- Automatic column recognition (exact names, then synonyms such as "NPI", "First name", "Email opt-in", "Customer ID"); first + last name combined; unknown columns become custom fields.
- Pre-import check: missing IDs/names, duplicate IDs, NPI Luhn check digit (prefix 80840), unreadable dates, unrecognized consent values — with row examples. Nothing is written until you confirm.
- Dates read as ISO, US `m/d/Y`, `d.m.Y`, Unix seconds or milliseconds. Consent reads yes/no, true/false, 1/0, opt-in/opt-out, granted/withdrawn.
- Background chunked import with a progress bar (no-JS fallback).
- Missing-value handling offered only for matched columns that have gaps.

**Scoring**
- Tiers A/B/C from the priority score (cut-offs in Settings) and priority deciles 1–10, recalculated after imports, rule changes and cron.
- HCP profile explains the score: consent, recency and engagement points, then each rule's change in run order.

**Rules**
- When/Then rule builder with a JSON view for nested logic; move rules up and down; test on all HCPs before saving (match count and before/after examples). Saving re-applies rules automatically for databases up to 20,000 HCPs.

**App**
- New interface: sidebar navigation, Overview with a five-step "first export" checklist, reachability and data-health tiles, audience summary and readable activity feed. HCP list with search, filters, sorting and paging.
- Roles: viewer, editor, admin. The 1.1 `user` role becomes editor. Admins can add people and change roles in Settings.
- One-click sample data (200 HCPs, 9 rules, 6 audiences) runs the import to completion.
- Readable audit log page with filters.
- `php litehcp.php action=selftest` runs 33 checks against an in-memory database and a temporary upload folder.
- Cron refuses the default `CHANGE_ME` token.

**Site**
- New landing page (`index.html`) with a "What's new in 1.2" section and upgrade notes; docs, README, SETUP, SECURITY and llms.txt updated (sample counts now 9 rules / 6 audiences).

**Security & platform**
- No third-party assets: system font stack, CSP `font-src 'self'` and `style-src 'self' 'unsafe-inline'`.
- API endpoints (`api_count`, `api_rule_test`, `api_import_chunk`) are POST-only with an `X-CSRF-Token` header.
- Session role is re-read from the database on every request, so role changes apply immediately.
- PHP 8.4 compatibility: explicit CSV escape arguments. PHP 8.0+ still supported; 8.3+ recommended. Admins see a warning on PHP versions that no longer receive security fixes (below 8.2).
- Schema migrations tracked in `PRAGMA user_version` (now 2).

## 2026-06-12
- App UI: replaced Bootstrap CDN with a self-contained, on-brand design system (paper background, electric-blue accent, mono detailing) — fully offline-capable; CSP tightened (removed cdn.jsdelivr.net).
- Demo data: embedded sample grew from 4 to 200 deterministic, realistic HCP profiles with believable gaps; enrichment rules (incl. consent suppression, recency boost, APP tagging); sample segments (incl. a suppression list).
- Site: rebuilt landing page; added docs.html, compare.html, three "alternative" SEO pages, demo.html (client-side interactive demo with a dataset bit-identical to the app sample), sitemap.xml, robots.txt, llms.txt, JSON-LD structured data.
- Docs: rewrote README; default accent color is now #2540FF.

## 2026-05-14
- Security hardening: CSP + security headers, CSRF on POST actions, server-side error logging (no stack traces), login/register/export rate limiting.
- SQL injection hardening: enforced prepared statements; tightened dynamic SQL to allowlist-based filters.
- Upload hardening: CSV-only validation (size/type), sanitized filenames, uploads directory access denial.
- UX: added footer links to SETUP/SECURITY docs and added a minimal landing page.
