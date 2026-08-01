# W09 UAT Matrix — 1.0.0-rc.1

Evidence classes:
- **AUTOMATED (PASS)** — covered by the backend Pest suite (823 passed / 2650 assertions,
  sequential) and/or the web Vitest suite (498 passed / 100 files), executed this wave in
  the cloud sandbox at the RC code.
- **LOCAL REQUIRED** — interactive browser click-through / running-stack behavior. Playwright
  (`apps/web/e2e/`: `smoke.spec.ts`, `a11y.spec.ts`, `visual/`) + axe are wired but need the
  full stack; run via `W09_WINDOWS_UAT.ps1` on a Docker host.
- **FIXED (W09)** — a defect this wave found and corrected, now regression-guarded.

## Domain coverage (automated suites cited)

| Domain | Automated suites (backend / web) | Status | Notes |
|---|---|---|---|
| Learner — auth | `tests/Feature/Identity/*` (9), `tests/Feature/Security/*` (4); web `tests/auth/*` | AUTOMATED PASS | register/login/logout/MFA/reset, invalid creds, session, safe redirect |
| Learner — catalog | `tests/Feature/Catalog/*` (11); web `tests/catalog/*` | AUTOMATED PASS | listing, search/filter, details, unpublished protection |
| Learner — enrollment/entitlement | `tests/Feature/Entitlements/*`, `Commerce/*`, `Subscriptions/*` | AUTOMATED PASS | free/paid/subscription entitlement, unauthorized denial, refund/grace |
| Learner — learning/player | `tests/Feature/Learning/*` (13) incl. `LearnPlayerPerformanceTest`; web `tests/learning/*` | AUTOMATED PASS + **FIXED** | player launch, curriculum, signed media, progress/resume/completion. **v1/ path 404 fixed** |
| Learner — assessment | `tests/Feature/Assessment/*` (7); web `tests/assessment/*` | AUTOMATED PASS | start/submit/retry/score/pass-fail, unauthorized attempt rejected |
| Learner — assignments | web `tests/assignments/*`; backend `Authoring/*` grading | AUTOMATED PASS + **FIXED** | draft/upload/submit/late/resubmit/grade. **v1/ path 404 fixed** |
| Learner — certificates | `tests/Feature/Certification/*` (5) | AUTOMATED PASS | eligibility, generation, public verification, unauthorized denial |
| Learner — billing | `tests/Feature/Commerce/*`, `CreditNotes`, web `tests/commerce/*` | AUTOMATED PASS | orders, invoices, credit notes, subscription state |
| Instructor | `tests/Feature/Authoring/*` (15), `Analytics/*` (9); web `tests/teach/*`, `authoring/*`, `gradebook/*` | AUTOMATED PASS + **FIXED** | course/curriculum/media/assignment/quiz authoring, publish, version history, gradebook, CSV export (streamed). **gradebook/media/versioning v1/ 404 fixed** |
| Commerce | `tests/Feature/Commerce/*` (9), `Payments/*` (3), `Refunds`, `Coupons/*` (2), `Tax`, `CreditNotes`, `Subscriptions` | AUTOMATED PASS + **FIXED** | checkout (server-authoritative total, coupons), payment states, webhook signature/replay, refunds, invoices/VAT, subscriptions. **Duplicate-submit double-charge fixed** |
| Admin | `tests/Feature/Admin/*` (4), `Security/*` (4) | AUTOMATED PASS | admin routing, role/permission mgmt, moderation, order/refund/webhook mgmt, tenant isolation, non-admin forbidden |
| Accessibility / RTL / browser | web `e2e/a11y.spec.ts`, `visual/`; `tests/i18n.test.tsx` | LOCAL REQUIRED | axe + Chromium desktop/mobile + AR RTL need the running stack (PS1) |
| Performance | `Learning/LearnPlayerPerformanceTest`, `Live/EventListPerformanceTest`, `Analytics/InstructorCourseStatsBatchTest`, `Database/PerformanceIndexAndSargableDateTest` | AUTOMATED PASS | constant-query-count assertions on player/events/instructor lists; index migration verified |

## Confirmed defects found & fixed this wave

| ID | Severity | Defect | Fix | Regression test |
|---|---|---|---|---|
| W09-D1 | HIGH | Web media/assignments/versioning/gradebook/player clients prefixed `v1/`, doubling to `/api/v1/v1/...` → 404 across the whole authoring/media/grading/player surface | Dropped the prefix in 7 files; paths now bare | `apps/web/tests/contract/no-double-v1-prefix.test.ts` |
| W09-D2 | HIGH | Checkout double-charge on duplicate submit (two orders, two gateway idempotency keys, one cart) | Per-user distributed lock across the full checkout incl. gateway call; `CheckoutInProgressException` (409) | `tests/Feature/Commerce/CartCheckoutTest.php` (concurrent-lock + duplicate-submit) |

## Performance — advisory only (no release blockers; from static analysis)
- N+1: none on audited hot paths (catalog, course details, curriculum, player, gradebook,
  orders, invoices all eager-load/paginate). CSV export streams via a chunked generator.
- Suggested query-count regression tests to add (gaps): catalog list, gradebook page,
  commerce lists (player/events/instructor already have them).
- Missing indexes (advisory, back-office / not hit today): `orders.status`,
  `invoices.issued_at`, `session_trainers.user_id` reverse lookup, catalog category/tag
  reverse pivots. Catalog title search uses leading-wildcard `ILIKE` (needs pg_trgm at scale).

## Resilience / observability / operations — LOCAL REQUIRED
Readiness-degradation (DB/Redis down → `/health/ready` fails while `/health/live` stays up),
the simulated incident, correlation-ID propagation, and queue/failed-job visibility exercise
the running stack and are covered by `W09_WINDOWS_UAT.ps1` (health probes) plus the existing
`tests/Feature/HealthTest.php`, `Queue/*`, `Notifications/*` unit coverage.
