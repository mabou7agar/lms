# HELBARON CoreLMS — IA / Route / UI Consolidation Audit

Repo: `corelms` · Branch: `feat/stage-4-enterprise-ai-growth-integrations` · Mode: read-first (no code changed yet)

Frontend config arrays (`config/nav.ts`, `config/theme.ts`) are route-accurate and match AppShell — treated as **source of truth**. The backend `NavigationSeeder` is a partial/stale copy; the consistent direction is to correct the seeder to match config.

---

## 1. Route inventory (89 `page.tsx`)

| Group | Count | Routes |
|---|---|---|
| Public (marketing) | 23 | `/`, about, advisory, categories, cohorts, compare(+[slug]), contact, courses(+[public_id]), enterprise, events(+[public_id]), p/[slug], pricing, privacy, products, solutions(+[persona]), terms, trainers, verify(+[code]), workshops |
| Auth | 6 | login, register, forgot-password, reset-password, mfa, verify-email |
| Admin (under (commerce)) | 4 | /admin/analytics, /admin/orders, /admin/coupons, /admin/credit-notes |
| Instructor | 12 | /teach, apply, courses(+[public_id] +/edit +/analytics +/gradebook +/students/[student]), media, students, earnings, sessions |
| Learner | 7 | dashboard, my-learning, continue-learning, certificates, learn/[public_id], lessons/[public_id], invitations/[token] |
| Org / Manager | 10 | /org(+/consulting +/organizations +[public_id]), /manager(+members, departments, seats, import, sso, branding) |
| CRM | 5 | /crm(+/leads +[public_id], /consulting, /accounts) |
| Analytics | 6 | /analytics, /reports(+/insights +[report] +[public_id]), /dashboards |
| Commerce / Account / Dev | ~16 | orders, billing(+[id]), subscriptions, contracts, cart, checkout(+success/failed), profile, security, notifications, design-system(dev) |

---

## 2. Navigation inventory (sources)

- **Frontend fallback:** `config/nav.ts` (9 sidebar arrays) + `config/theme.ts` (header `nav`, 3 footer columns, `footer.legal`, CTAs).
- **CMS seed:** `NavigationSeeder` seeds 10 `MenuLocation`s: public-header, public-footer (Learn/For-Business/Company), learner-sidebar, instructor-sidebar, organization-sidebar, legal-menu, utility-menu (+ admin-quick-links/mobile-menu/mega-menu empty).
- **Resolution rule (`app-shell.tsx:48`, `landing-header/footer`):** `useNavigation(location)` — if CMS menu is non-empty it **wins**; else the hardcoded config renders. So a *partial* CMS seed silently hides newer config-only items.

---

## 3. Duplicate surfaces

**F1 — Homepage double course grid (CONFIRMED).** `(marketing)/page.tsx:98-99` renders CMS blocks **and** unconditionally appends demo `<FeaturedCourses/>` (`components/marketing/featured-courses.tsx`, gated only by `DEMO_ENABLED=true` in `config/demo.ts:11`). When a CMS `featured_courses` block exists, **both** render — real API courses + 9 fake demo cards. Canonical = the CMS block (`homepage/blocks/featured-courses-block.tsx`, API-resolved). → owner decision.

**F2 — Learner player duplication (CONFIRMED).** `components/learning/player/CoursePlayerShell.tsx` (full-featured player + player-local `CurriculumSidebar`) is **never mounted** (dead subtree incl. LessonView/ProgressDisplay/player-hooks). The live route `learn/[public_id]/page.tsx` reimplements a thinner layout using a *different* `components/learning/curriculum-sidebar.tsx`; actual playback is a separate `lessons/[public_id]` route. Also `(learning)/(player)/layout.tsx:9-12` wraps the **authenticated** player in **marketing chrome** (AnnouncementBar/LandingHeader/LandingFooter). → owner decision.

---

## 4. Backend/frontend contract mismatches

**F6 — CRM lead convert (frontend comment is FALSE).** `crm/leads/[public_id]/page.tsx:89` disables convert claiming "no endpoint", but `POST leads/{lead}/convert` **exists and is implemented** (`crm.php:21`, `LeadController::convert`, binds by `public_id`). Fix = frontend-only: add `useConvertLead` + enable the button. Separately, no `GET leads/{lead}` show exists — detail is resolved by scanning a 100-row list (`page.tsx:20`); optional ~5-line backend `show` endpoint to remove the scan.

**F7 — CRM accounts jumps out of CRM IA.** `crm/accounts/page.tsx:52` links "View" → `/org/organizations/{id}` (leaves CRM group; no `/crm/accounts/[id]`). Frontend-only fix.

---

## 5. Orphan / dead-link / stale-nav report

| # | Issue | Evidence | Scope |
|---|---|---|---|
| F3 | instructor CMS seed omits Media/Earnings/Sessions (7 config items → 4 seeded) | `nav.ts:40-48` vs `NavigationSeeder:135-140` | seeder |
| F4a | footer "Certificates" → `/certificates` (auth) in seed vs `/verify` (public) in theme | `NavigationSeeder:101` vs `theme.ts:151` | seeder → /verify |
| F4b | "Become an instructor" → `/trainers` in seed vs `/teach/apply` in theme | `NavigationSeeder:103` vs `theme.ts:153` | seeder → /teach/apply |
| F4c | `legal-menu` seeded+typed but footer renders `brandTheme.footer.legal`; no `useNavigation("legal-menu")` | `landing-footer.tsx:140`; grep | wire footer legal to CMS w/ fallback |
| F4+ | seed Company "Organizations" → `/org` (auth) vs theme `/enterprise`; seed missing Products & Solutions | `NavigationSeeder:119` vs `theme.ts:147,169-170` | align seeder to theme |
| F5 | `/teach/apply` public CTA → RequireAuth wall → `ComingSoon` stub | `theme.ts:153`, `messaging.ts:175`; `(instructor)/teach/apply/page.tsx`; `(instructor)/layout.tsx:19` | owner decision |
| F8 | sitemap missing `/solutions`, `/compare` | `sitemap.ts publicRoutes` | add 2 routes |

Admin/Filament: no navigation orphans (all resources registered); 16 list "New" buttons already added in a prior wave.

---

## 6. Prioritized fix plan

**A. Safe scoped fixes (no product decision) — implement now:**
1. `sitemap.ts` — add `/solutions`, `/compare` (F8).
2. `NavigationSeeder` — add instructor Media/Earnings/Sessions (F3); Certificates→/verify (F4a); Become-instructor→/teach/apply (F4b); Company Organizations→/enterprise + add Products & Solutions (F4+). Seed = mirror of config.
3. Footer legal strip — wire `useNavigation("legal-menu")` with `?? brandTheme.footer.legal` fallback (F4c).
4. CRM convert — add `useConvertLead` mutation, enable the button (F6, frontend-only).
5. CRM accounts — add an explicit "opens in org workspace" affordance on the View link (F7, smallest).

**B. Needs your decision (3 forks) — then implement:** F1 homepage demo courses · F2 learner player · F5 teach/apply CTA.

**C. Out of scope / deferred:** optional `GET leads/{lead}` show endpoint (F6); full in-CRM `/crm/accounts/[id]` page (F7 larger); backlog #22 (wire real course/instructor images) proceeds independently.

**Guardrails honored:** no `apps/api` business logic touched; CourseCover visual system untouched; Phase-1 systems untouched. Gates run before any commit; no commit until green + reviewed.
