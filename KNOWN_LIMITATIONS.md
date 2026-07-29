# Known Limitations — CoreLMS

Consolidated from the hardening-phase audits (archived in `docs/reviews/_archive/hardening-2026-07/`). **None of these block production.** They are additive enhancements and low-priority polish, listed for transparency and future planning.

## Deferred subsystems (RC1 decision)
- **Automation engine — FORMALLY DEFERRED (not shipped in v1.0.0).** `WorkflowEngine`, `DigestService`, and the `ScheduledAutomation` model + `automation_rules` migration exist and are schema-complete, but have **no execution path** (no route, command, job, or scheduler entry invokes them; a repo-wide reference scan finds zero callers). Per the Sprint 11 go/defer gate, building the trigger/consumer wiring is deferred to a post-v1.0 sprint. The code is inert and harmless at runtime; it is retained (rather than deleted) so the future build starts from the existing schema. **No customer-facing automation/digest behaviour is advertised for v1.0.**
- **Live-session reminders (H9) — DEFERRED.** `SessionReminder` rows are written with `status=Pending` but no consumer delivers them. The reminder feature is not functional in v1.0; either disable its UI affordance or ship the scheduled consumer before advertising reminders.

## Performance
- Lighthouse **Performance 72** (mobile, 4× CPU + slow-4G, API-down shell). The deficit is **entirely LCP** (the app is a client-rendered shell, so the largest paint waits on JS hydration under throttling). TBT (100 ms), CLS (0), Speed Index (2.6 s) are near-perfect. A representative production run (API up / `--preset=desktop`) has not been captured. Improving LCP is structural work (reduce the client boundary), not a defect.
- Tier-1 config optimizations (`optimizePackageImports`, `removeConsole`, browserslist) yielded a marginal ~1 kB/route; the shared JS baseline (102 kB) is unchanged. lucide-react is already optimized by Next's defaults.

## White-label
- Transactional emails (OTP, password reset) render via `config('app.name')`, not the admin `BrandSetting.email` group (`email_logo`, header/footer/colours/signature) — that group is currently unused.
- ~15 marketing route files + the i18n dictionaries hardcode the brand name as a literal instead of reading admin branding (all have valid fallbacks; not a leak).
- Footer locations + contact email are hardcoded in `config/theme.ts` / `contact/page.tsx` instead of `identity.address` / `support_email`.
- Two overlapping certificate-branding sources exist (`CertificateSetting` is live; `BrandSetting.certificate` is defined but unused).

## SEO
- No default/dynamic `og:image` (only set when an admin provides one per entity).
- Course detail pages emit no `Course` JSON-LD (event detail has `Event` schema).
- No `BreadcrumbList` JSON-LD and breadcrumbs are not shown on real pages.
- hreflang effectively absent (locale is cookie-based; no `/en`,`/ar` path variants).
- Sitemap omits dynamic catalog detail URLs unless the managed SEO API returns them.

## Backend / Database
- Course-announcement notification fan-out is **synchronous and unbounded** — request time scales with enrollment; large courses can time out. Should be a queued, chunked job. (Highest-value backend item before high-enrollment launches.)
- Public Events listing has an N+1 speaker lookup (~12–24 extra queries/page); a batch pattern already exists elsewhere to copy.
- First certificate-PDF download renders Chromium synchronously in-request (idempotent/cached after); should pre-generate on `CertificateIssued`.
- Minor: `course_trainer` pivot lacks a unique `(course_id,user_id)`; `courses.published_at` (list sort key) is unindexed.

## Technical debt (Low priority)
- 39 arbitrary Tailwind bracket values (some legitimate micro-typography) could map to scale tokens.
- 3 raw `<button>` in feature components could use `ui/Button`.
- `components/ui/breadcrumb.tsx` is used only by the design-system showcase.
- No exhaustive `ts-prune`/`depcheck` sweep yet (one dead dependency, `framer-motion`, already removed).

## Security
- CSP uses `script-src 'unsafe-inline'` in production (documented; Next inline runtime). A nonce-based CSP is an optional future hardening.
- `.trivyignore` carries a picomatch exception (CVE-2026-33671); the upstream fix exists (≥4.0.4) and the exception covers only Next's vendored copy — removability is a one-line CI test if desired. Not runtime-exploitable (build-time tooling only).

## Environment
- Repo lives on a Windows `D:\` (NTFS) path; the Laravel dev container bind-mounts it. Relocating into the WSL2 filesystem is the definitive dev-speed fix (unmeasured here; the app build itself is fast — `npm run build` ~10–30 s, web tests ~24 s).
