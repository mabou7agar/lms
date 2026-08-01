# CTO HANDOFF — HELBARON / CoreLMS

The one file another AI needs to continue. Rewritten after every major batch. Compact but complete.
Last updated: 2026-07-30 (post-W07, installing the `.ai/` collaboration layer).

## Repository state
- Bilingual (EN/AR, RTL) MENA LMS. Backend `apps/api` (Laravel 12, PHP 8.4, Postgres 16, Redis:6380, Filament, DDD). Frontend `apps/web` (Next.js 15 App Router, React 19, TS strict, Tailwind 4, TanStack Query). Device repo root: `D:\Claude_Files\Projects\LMS\CoreLMS Implementation\corelms`. Legacy `corelms-api` = do not touch.
- **No git commits** (standing instruction "Do not create a git commit yet"). Sync = file-write + SHA-256 byte-identity, verified per wave. Branch/version history: none yet.
- **All 9 gates green** (last verified 2026-07-30, cloud sandbox against real code): migrate:fresh --seed, PHPUnit **808**, PHPStan **0**, Deptrac **0**, Pint; Typecheck, Lint (0 errors), Vitest **484**, Build. Plus additional QA: Playwright E2E (chromium public) + axe a11y PASS.

## Current wave
- W07 (Independent QA & Verification) COMPLETE. Now: installing `.ai/` (this layer). No product code changes in this step.

## What changed most recently (W07) & why
Fixed all reproducible launch-critical defects from adversarial audits (details in `../reports/W07.md`). Highest-impact:
- **Admin console crash**: 5 commerce controllers used `success(collection)` on a paginator, dropping `meta/links` → frontend TypeError. → `ApiResponse::paginated()`.
- **Dunning crashed on Postgres**: `PaymentRecoveryService::record()` did `lockForUpdate()->max()` (illegal on PG). → locked ordered `value()`. (Was untested — added a test.)
- **Money/ledger**: partial refund via webhook no longer marks the whole order Refunded; `payment.succeeded` settles one charge not all; invoice lines apportion the coupon discount so they reconcile; invoice numbers locked (no count()+1); coupon redemption reconciled on dunning-paid orders.
- **Learning**: completed-enrollment learners can take/retake course assessments (`hasCourseAccess`); legacy `/progress` completion now enforces `LessonCompletionPolicy` (was a certificate-forgery bypass); video completion is server-authoritative (no client-duration trust).
- **Frontend**: mobile drawer closes on nav; instructor nav no longer leaks on `/teach/apply`; sidebar single `aria-current`; coupon `plans` scope removed (backend rejects it).

## Files modified (W07)
See `../context/project_state.json` → `modified_files_last_wave_W07` (30 code/test files + `docs/verification/W07_LOCAL_WINDOWS_VERIFICATION.md`). All byte-identical on device (31/31).

## Architecture changes
- New port method `CourseEnrollmentPort::hasCourseAccess(courseId,userId)` (active OR completed) + adapter + 2 test doubles.
- New listener `ReconcileCouponRedemptionOnOrderPaid` (registered on OrderPaid).
- No layer/boundary changes; Deptrac still 0. Full map in `../context/architecture.md`.

## Security changes
- Closed: quiz IDOR (W06), certificate forgery via legacy progress, forced video completion, partial-refund-as-full, OTP guess ceiling, coupon-scope 422, unclamped per_page.
- **Open (product decision):** login user-enumeration + email-only lockout DoS (MED). Uniform-401 fix vs current UX — awaiting user choice.

## Performance changes
- Invoice-number and dunning-attempt allocation are locked/correct on Postgres. (Pagination + gradebook streaming were W06.)

## New tests
- +5 backend (808 total): idempotency-key-advances (fixed tautology, exposed the dunning bug), gradebook chunk-boundary (>200), PartialRefundWebhookTest, InvoiceReconciliationTest, completed-enrollment attempt.

## Remaining blockers
- None launch-critical. (See product decision below.)

## Remaining technical debt
- No git/version history. Sandbox-only composer hacks not for the device. Filament admin lacks HTTP contract tests. Minor a11y polish deferred. Full list: `../verification/pending_items.md`.

## Next recommended wave
- First: resolve the login-hardening product decision + (recommended) bootstrap git. Then W08 (proposed) — see `../prompts/next_wave.md`.

## Requires manual Windows verification
- Authenticated Playwright journeys + live-service security spot-checks. Guide: `docs/verification/W07_LOCAL_WINDOWS_VERIFICATION.md`; index: `../verification/local_checks.md`.

## Requires a product decision
- Login enumeration/lockout-DoS: harden (uniform response + decaying lockout) or keep the current per-status UX? Nothing else is blocked on it.

## How to work here (rules for the next AI)
- Edit the REAL repo. Never weaken/regenerate PHPStan or Deptrac baselines; never skip/delete tests; no fake green. Money = integer minor units. Idempotency mandatory on payments/orders/refunds/subscriptions/webhooks; verify webhook signatures in the adapter; no gateway SDK calls outside adapters; no client-trusted price/tax. Paginated endpoints return `{data,meta,links}` via `ApiResponse::paginated`. Keep all 9 gates green after every batch and re-verify byte-identity on sync. Update this `.ai/` layer continuously.
