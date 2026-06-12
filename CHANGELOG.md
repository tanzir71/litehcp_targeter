# CHANGELOG

## 2026-06-12
- App UI: replaced Bootstrap CDN with a self-contained, on-brand design system (paper background, electric-blue accent, mono detailing) — fully offline-capable; CSP tightened (removed cdn.jsdelivr.net).
- Demo data: embedded sample grew from 4 to 200 deterministic, realistic HCP profiles with believable gaps; 8 enrichment rules (incl. consent suppression, recency boost, APP tagging); 5 segments (incl. a suppression list).
- Site: rebuilt landing page; added docs.html, compare.html, three "alternative" SEO pages, demo.html (client-side interactive demo with a dataset bit-identical to the app sample), sitemap.xml, robots.txt, llms.txt, JSON-LD structured data.
- Docs: rewrote README; default accent color is now #2540FF.

## 2026-05-14
- Security hardening: CSP + security headers, CSRF on POST actions, server-side error logging (no stack traces), login/register/export rate limiting.
- SQL injection hardening: enforced prepared statements; tightened dynamic SQL to allowlist-based filters.
- Upload hardening: CSV-only validation (size/type), sanitized filenames, uploads directory access denial.
- UX: added footer links to SETUP/SECURITY docs and added a minimal landing page.
