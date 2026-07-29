# Step 6–9 Engineering Audit

**Scope:** Read-only senior engineering design review of CoreLMS / HElbaron LMS after Step 5 completion.
**Repository:** `corelms` monorepo — `apps/api` (Laravel 12, Filament v4, PostgreSQL, Redis/Horizon), `apps/web` (Next.js 15).
**Date of review:** 2026-07-21.
**Method:** Static analysis only. Four parallel read-only sub-audits (notifications/automation, security, performance/analytics/caching, dead-code/docs) plus direct inspection of CI, infrastructure bootstrap, health probes and error handling. **No code was executed** — the reviewer cannot run PHP or the test suites in this environment, so every "passes/fails" claim about gates is inherited from the last host run, not re-verified here. This is stated explicitly wherever it matters.

---

# Executive Summary

CoreLMS is a **well-architected, unusually disciplined codebase** carrying a small number of **genuinely serious defects** concentrated in the asynchronous and payment layers. The domain model, bounded-context enforcement (Deptrac + a dedicated PHPStan architecture ruleset), authorization design (layered role → permission → ownership scoping), input sanitization (double XSS defense), and CI pipeline are all above the bar for a v1 release. The problems are not spread thin across the app; they cluster in five places that did not receive the same care as the rest: **payment webhook verification, the notification delivery pipeline, background-job resilience, a handful of unbounded analytics queries, and a systemic missing-index gap.**

One finding is a **live, exploitable payment bypass** (fixed in the immediately preceding execution, pending gate validation). Several others are silent-failure classes — notifications that mark themselves delivered without sending, exports that hang forever in `processing`, dead-lettered messages nobody observes — that will not surface in a demo but will erode trust in production.

The single most important structural observation: **large subsystems are built, migrated, and wired to nothing.** `WorkflowEngine`, `DigestService`, `ScheduledAutomation`, live-session reminders, and multi-channel notification delivery all exist as code and schema but have no execution path. In practice **only in-app notifications are ever produced.** This is not dead code to delete — it is unfinished Step 6 work whose scaffolding is already present. That materially lowers the effort estimate for Step 6 while raising the risk of shipping a half-wired feature that looks complete.

**Bottom line:** the codebase is closer to production-ready than most at this stage, but it is **not** release-ready today. Two release blockers must clear (payment webhook — believed fixed; notification double-send/dedup), and roughly two to three weeks of focused hardening across Steps 6–9 stands between here and a defensible Release Candidate.

---

# Overall Production Readiness Score

## 72 / 100

Rationale for the number:

- **Architecture & code quality (weight high): ~88.** Clean contexts, ports/adapters, enforced boundaries, honest availability envelopes, minimal TODOs (6 total, all justified), zero removed-tech drift in docs.
- **Security (weight high): ~70.** Excellent baseline (IDOR-clean, no mass assignment, no SQL injection, strong headers/CSP/CORS) dragged down by one critical (payment) and medium items (token expiry, signed-URL ownership).
- **Async / notifications / automation (weight high): ~45.** The weakest area. Multiple silent-failure classes, decorative dedup, unbounded synchronous fan-out, whole features unwired.
- **Performance & DB (weight medium): ~65.** Mostly disciplined, but a systemic FK-index gap and two unbounded in-PHP aggregations that OOM at scale.
- **Observability & CI/CD (weight medium): ~80.** Structured logging, Sentry, Horizon, health probes, and a genuinely strong CI pipeline; let down by no logging in the delivery path and no correlation-id propagation into queued work.
- **Frontend (weight medium): ~82.** Green gates, availability-envelope discipline, RTL/i18n, one dead full-stack chain and stale visual baselines.

The score is a weighted blend, deliberately conservative: unresolved silent-failure classes in the money and messaging paths cap it below 75 regardless of how good the architecture is.

---

# Critical Issues

**C1 — Payment webhook accepted without a signature (auth bypass → free courses).**
`FakeGateway::parseWebhook` verified the signature only when one was present (`if ($signature !== null && ...)`), so omitting the header skipped verification entirely. The route is public and unthrottled, `fake` is the default provider and the value in the current `.env`, and the payload flips an order to `Paid` and grants course access. **Fixed in the preceding execution** (fail-closed + production guard + 5 regression tests) but that fix is **unvalidated** — the backend gates have not run against it. Until Pest confirms, treat as open.

**C2 — Rate-limited notifications are silently dead-lettered, never sent.**
`DeliverNotificationJob:50` calls `release(30)` on a rate-limit trip; `release` increments the job attempt counter, so after `tries()=3` the message is marked `Dead` **having never been attempted once**. Worst in exactly the bulk fan-out case rate limiting exists for. Data loss with no operator signal.

**C3 — Notification dedup is decorative; retries double-send.**
`dedup_key` has no unique index, and the `firstOrCreate` is keyed on a `notification_id` created one line earlier, so it can never match an existing row. The default key embeds `now()->format('YmdHi')`. Retrying the same domain event produces a second full notification and a second send. No caller ever passes `$dedupKey`.

**C4 — Export job hangs forever in `processing` on timeout.**
`ProcessExportJob` dispatched to `default` (60s supervisor timeout) instead of the purpose-built `exports` queue (300s), had no `$timeout`/`$tries`/`failed()`. On timeout the worker is killed before the `catch` runs, leaving the row permanently `processing` — an export that never finishes and never fails. **Fixed in the preceding execution**, unvalidated.

**C5 — Two analytics reports load unbounded tables into PHP.**
`ReportingService::retention()` materializes the entire `enrollments` and `lesson_progress` tables into PHP arrays, then filters by date *after* loading. OOMs at scale. Expressible as one grouped SQL query with the window in the `WHERE`.

---

# High Priority Issues

**H1 — Systemic missing indexes on FK/filter columns.** PostgreSQL does not auto-index FKs; the schema uses `constrained()` almost everywhere without follow-up `index()`. Missing where it hurts: `enrollments.course_id` (hottest table, never a leading column), `orders.paid_at`, `order_items.{order_id,product_id}`, `product_courses.course_id`, `course_trainer.user_id`, `certificates.issued_at`, `lesson_progress.completed_at`, `courses.published_at` composite. One migration transforms every report and instructor page.

**H2 — Per-lesson N+1 in the learner course player.** `LearnController:42-48` calls `LessonAccessService::canAccessByUserId` per lesson; each lands in 2–3 queries and re-fetches an enrollment the controller already holds. A 120-lesson course ≈ 240–360 queries per page load, learner-facing hot path.

**H3 — Instructor course list loops `courseStats()` unpaginated.** `Instructor\CourseController::index` runs 3+ queries per course over an unbounded `->get()`. The batched `CoursePerformanceService` already solves exactly this and documents itself as existing for the reason — this endpoint simply doesn't use it.

**H4 — Synchronous, unbounded notification fan-out on announcement create.** `AnnouncementController:50-65` plucks every enrolled user and dispatches inline in the request. A 5–10k-student course ≈ 25k queries in one HTTP request; it times out mid-fan-out leaving a partially notified cohort with no resume path. Not queued, not chunked.

**H5 — Dead-letter and delivery events have zero listeners.** `NotificationDeadLettered` / `NotificationDelivered` are dispatched but subscribed by nothing — no alert, no metric, no widget. A delivery can go `Dead` and nothing anywhere observes it. Combined with `queue:prune-failed --hours=168`, evidence of failures is destroyed on a 7-day timer.

**H6 — Only in-app notifications are ever produced.** `default_channels = ['in_app']`; the only caller passing other channels is `WorkflowEngine`, which is unwired. Email/SMS/push channels resolve but are never selected. `WhatsAppChannel` is an empty no-op that still marks deliveries `Sent`, so the ledger claims messages were delivered that never were.

**H7 — Sanctum tokens never expire.** `config/sanctum.php:17` → `expiration => null`. A stolen bearer token is valid forever unless the user logs out or resets their password. The web BFF cookie is 14 days but the underlying token outlives it.

**H8 — No caching on the 12 analytics insight endpoints.** The most expensive queries in the app (multi-table joins over full history) re-run on every dashboard load. `KpiEngine` is the only cached path; its pattern is not extended to `ReportingService`. Additionally, insight reports aggregate all rows then paginate/sort in PHP (`array_slice`), so `per_page` buys nothing.

**H9 — Live-session reminders written but never delivered.** `FakeReminderScheduler` creates `SessionReminder` rows with `status=Pending`; no command, job, or query ever reads them. Every reminder sits pending forever.

---

# Medium Priority Issues

**M1 — Signed file URLs carry no ownership check.** Export and certificate file handlers resolve by `public_id` with only the URL signature as authorization. Signatures are unforgeable (HMAC/APP_KEY, 15-min TTL) so this is defensible, but the URLs are bearer-equivalent and leak via history/logs/Referer. Add an owner check as defense in depth.

**M2 — No correlation-id propagation into queued work.** `CorrelationProcessor` reads `request()->header('X-Correlation-ID')`; inside a worker `request()` is a synthetic console request, so `correlation_id` is absent from every job log line. The file's own docblock claims queue traceability that isn't implemented.

**M3 — Zero logging in the entire notification delivery path.** `DeliverNotificationJob` has no `Log::` call — not on send, retry, or dead-letter. Failures survive only as a 500-char DB string, overwritten each attempt.

**M4 — Every listener is synchronous.** No listener implements `ShouldQueue`. A single `UserRegistered` synchronously triggers notification dispatch, analytics rollup, and OTP sends before the response returns. `SessionScheduled` runs an unbounded N+1 registration loop in-request.

**M5 — `whereDate()` defeats indexes.** `EnrollmentStatsAdapter` and `CoursePerformanceService` filter with `whereDate(col, …)`, which emits non-sargable `DATE(col)=?`. Even after H1's indexes these stay sequential scans; use half-open range comparisons.

**M6 — Duplicated aggregate logic.** Enrollment-aggregate SQL exists in 3 places (`EnrollmentStatsAdapter` — canonical, `InstructorAnalyticsService`, `ReportingService`); completion-rate arithmetic reimplemented inline in `ReportingService` twice despite `EnrollmentStats::completionRate()`; six ad-hoc `round(($a/$b)*100,2)` sites. `InstructorAnalyticsService` bypasses `EnrollmentStatsPort` entirely.

**M7 — Unpaginated collection endpoints.** ~12 endpoints return `->get()`/`->all()` on unbounded sets: `/my-learning`, `/my-certificates`, `/teach/courses`, course announcements, `/contracts`, `/consulting`, `/reports`, `/dashboards`, `/seo/sitemap` (public), `/pages`, `/devices`.

**M8 — Export streamed through memory.** `ExportController::file` does `response($disk->get($path))`, reading the whole export into a PHP string; a large XLSX exhausts `memory_limit`. Use `download()`/`StreamedResponse`.

**M9 — Rate-limiter keying and coverage gaps.** `identity-register`/`identity-password` key on IP alone (distributed-source bypass). Unthrottled public endpoints include `/courses`, `/categories`, `/trainers`, `/live-sessions`, `/branding`, `/navigation`, `/pages`, `/seo/*`, `/feature-flags`, and **`POST /payment/webhook`**.

**M10 — `->can()` under Sanctum silently denies.** `LiveSessionPolicy` and `CertificatePolicy` use `$user->can('...')`, which resolves a Spatie guard Sanctum doesn't match — fails closed, so live-session management and certificate revoke/reissue are unreachable for anyone but `super_admin`. The codebase documents this pitfall elsewhere and uses `hasPermission()`; these two policies didn't get the memo.

**M11 — `VERSION` disagrees with release docs.** `VERSION`/`README` say `1.0.0-rc.1`; `RELEASE_NOTES.md`/`FINAL_PROJECT_STATUS.md` say 1.0.0 shipped; `CHANGELOG` stops at rc.1. `VERSION` likely feeds build tooling.

---

# Low Priority Issues

- **L1** — `APP_DEBUG=true` in committed `.env.example` (`.env` is gitignored; Laravel 12 defaults debug to false, so template hygiene not live exposure).
- **L2** — Three models with `$guarded = []` (all internal pivot/audit, never populated from request data).
- **L3** — `NotificationDelivery.provider` column never populated; `DeliveryStatus::Failed` never assigned (transient-failure state unobservable).
- **L4** — `TemplateRenderer` runs 2 uncached queries per render on the hot path; renders the in-app template twice per user during fan-out.
- **L5** — Over-eager loading on the public catalog list (`with(['level','language','categories','tags','trainerLinks'])` but the list Resource reads only `level`,`language`).
- **L6** — Only one of six scheduled tasks has overlap protection (low impact — the others are idempotent prunes).
- **L7** — `OtpService::sendSms` logs the plaintext OTP (guarded to `local`, still a credential in log output).
- **L8** — ~13 unused exceptions, 3 unused traits (incl. `HasTranslations` in a bilingual product), ~16 dead Actions/Services, several dead frontend hooks/functions.
- **L9** — 108/584 i18n keys unused (many false positives via dynamic keys; ~40 genuine orphans); no parity test on the global dictionary (there is one on the authoring dictionary).
- **L10** — `auth/mfa/disable` and `auth/verify-phone` routes exist with no caller and no test — half-finished MFA, arguably a support gap not dead code.
- **L11** — Stale marketing visual baselines (`about`, `contact`) — pre-existing, content-driven, not a Step 5 regression.

---

# Area-by-Area Audit

## 1. Notifications

**Current State.** A well-modeled notification/delivery split: `Notification` + per-channel `NotificationDelivery` ledger, per-user locale settings, localized templates with locale→fallback lookup, `read_at`/`archived_at` timestamps with `scopeUnread`/`scopeActive`, correct unique constraints. Channel/provider abstraction with fake-by-default resolution. API exposes index/show/read/preferences.

**Findings.** The schema and localization are genuinely good (see strengths). The *runtime* is where it breaks: rate-limited deliveries dead-letter without sending (C2); dedup is non-functional (C3); dead-letter/delivered events have no listeners (H5); only in-app is ever produced (H6); `WhatsAppChannel` lies about delivery (H6); zero logging in the path (M3); `provider` column and `Failed` state unused (L3); template lookups uncached and double-rendered (L4). No unread-count, archive, or mark-all-read endpoint despite the model supporting all three.

**Risks.** Production: silent message loss and duplicate sends — both erode user trust invisibly. Maintainability: a ledger that records `Sent` for messages never sent makes every future debugging session start from a false premise.

**Recommendations.** Make delivery claiming atomic (`where('status','pending')->update(...)`), fix dedup with a real unique index and a deterministic key, subscribe the dead-letter event to an alert + Filament widget, gate `WhatsAppChannel`/unwired channels so they mark `Failed` not `Sent`, add logging, expose unread-count/archive/mark-all-read. **Effort: 3–5 days.**

## 2. Automation / Queues / Scheduler

**Current State.** Horizon installed with three dedicated supervisors (default/notifications/exports) and per-environment process scaling; `failed_jobs` and `job_batches` migrated; `after_commit=true` on both connections; Sentry captures job exceptions; six scheduled prune/snapshot tasks.

**Findings.** Queue *config* is correct; queue *usage* is not. Export job misrouted and unresilient (C4, fixed-unvalidated). No job implements `ShouldBeUnique`/`$uniqueId`; delivery idempotency rests on a non-atomic check-then-act. Every listener synchronous (M4). `WorkflowEngine`/`DigestService`/`ScheduledAutomation` are built and migrated but have no execution path. Live-session reminders never consumed (H9). Only one scheduled task has overlap protection (L6).

**Risks.** Scalability: synchronous fan-out and listeners will time out requests under load. Production: duplicate processing on any re-reservation.

**Recommendations.** Queue the listeners (`ShouldQueue` + `ShouldHandleEventsAfterCommit`), add a reminder-dispatch scheduled command, wire or explicitly defer the automation engine. **Effort: 3–4 days** (plus whatever scope you assign the automation engine — see Major Projects).

## 3. Analytics

**Current State.** 12 admin insight endpoints + KPI engine + export pipeline. Authorization is consistently layered (role → permission → scope) with a deliberate platform-wide refusal for instructors and a revenue-permission gate. KPI engine is cached.

**Findings.** `retention()` unbounded-in-PHP (C5); course/instructor/organization performance aggregate-all-then-PHP-paginate (H8); no caching on insight endpoints (H8); enrollment aggregate duplicated (M6). Authorization itself is clean — no gaps found.

**Risks.** Scalability: the reports that matter most to admins are the ones that OOM first.

**Recommendations.** Push pagination/sort into SQL, extend the `KpiEngine::cached()` pattern, rewrite `retention()` as one grouped query. **Effort: 3–4 days.**

## 4. Reporting

**Current State.** `ReportingService` centralizes ~12 report builders; revenue/commerce/certificates/live-sessions/learner-activity/CRM run in SQL correctly.

**Findings.** Duplicated completion-rate and enrollment-aggregate logic (M6); `completionFunnel` runs 5 sequential COUNTs over the same window; revenue-by-course join near-duplicated. Five insight reports have no caller and uneven test coverage vs their siblings.

**Risks.** Maintainability: six inline rate calculations drift independently.

**Recommendations.** Extract a shared `Rate::percent(int $n, int $d)` and route through `EnrollmentStats`/`EnrollmentStatsPort`; collapse the funnel into one conditional-aggregate query. **Effort: 2 days.**

## 5. Caching

**Current State.** The entire application has **one** `Cache::` call — `KpiEngine`, `analytics:*` keys, 300s TTL.

**Findings.** No tags, no invalidation (new snapshots don't bust keys), no user/role/tenant discriminator. Safe today only because analytics isn't tenant-scoped yet; the `TenantContext`/`TenantScope` infrastructure exists, so the keys become a cross-tenant leak the moment analytics gains a tenant dimension. `FeatureFlagService` re-reads the full table every request (per-request in-memory only).

**Risks.** Security (latent): cache leakage across tenants once multi-tenancy activates. Performance: uncached reports.

**Recommendations.** Add key discriminators now (cheap, future-proofs the leak), add invalidation on snapshot write, cache feature flags. **Effort: 1–2 days.**

## 6. Performance

**Current State.** Genuinely disciplined — Resources use `whenLoaded` in 28 of ~29 sites, aggregates mostly run in SQL, `CoursePerformanceService` is textbook batched.

**Findings.** The exceptions are concentrated: H2 (learner player N+1), H3 (instructor list), C5 (retention), M5 (`whereDate`), M7 (unpaginated), M8 (export memory), plus an N+1 user lookup in `EventListResource` and over-eager catalog loading (L5).

**Risks.** Scalability: the learner player and instructor list degrade linearly with course size.

**Recommendations.** As above; hoist and batch the player, swap the instructor list onto the existing batched service. **Effort: 3–4 days** (overlaps H-items).

## 7. Database

**Current State.** Schema design is strong — sensible unique constraints, composite indexes where the code queries them, honest nullable timestamps.

**Findings.** One systemic gap: FK columns unindexed because Postgres doesn't auto-index `constrained()` (H1). Several composite-primary tables missing the trailing-column index needed for report joins. Missing date indexes on report-filtered timestamps (I9 in the source audit).

**Risks.** Scalability: every `where('course_id')`/`whereBetween('paid_at')` sequential-scans the hottest tables.

**Recommendations.** One additive index migration. **Effort: 0.5–1 day** (writing), plus review of query plans.

## 8. API

**Current State.** REST-only under `/api/v1`, JSON-forced via `ForceJsonForApi`, standard envelope, per-domain route loading, correlation-id and security-header middleware, tenant resolution scoped to the API group, feature-flag route guard.

**Findings.** Error handling is well-designed — the `AuthenticationException` render override prevents the classic 500-on-missing-login-route trap. Gaps are the unpaginated endpoints (M7), unthrottled public routes (M9), and the two dead half-features (L10). The BFF proxy on the web side re-encodes path segments and enforces Origin — good.

**Risks.** Production: unbounded list responses; unthrottled public surface incl. the payment webhook.

**Recommendations.** Paginate the list endpoints, throttle the public surface (webhook first). **Effort: 1–2 days.**

## 9. Frontend

**Current State.** Instructor Dashboard 2.0 shipped green (typecheck/lint/vitest/build/storybook all passed on host); availability-envelope discipline, RTL/i18n parity (584 keys, zero drift), section isolation, non-optimistic mutations.

**Findings.** One dead full-stack chain (`useTeachDashboard`→`getTeachDashboard`→`GET /teach/dashboard`); several dead hooks/functions; ~40 genuine orphan i18n keys; stale `about`/`contact` visual baselines (L11); no Storybook stories for the new dashboard components; filters not URL-synced (consistent with the rest of the app); `t()` has no interpolation. **The dashboard has never been rendered in a real browser** — the authenticated e2e tests are skipped for lack of an auth fixture.

**Risks.** Maintainability: dead chain and orphan keys; Confidence: no browser-level verification of the flagship new page.

**Recommendations.** Delete the dead chain end-to-end, add the auth fixture and un-skip authenticated e2e, regenerate baselines after human review. **Effort: 1–2 days.**

## 10. Security

**Current State.** Strong baseline. IDOR-clean (every id-taking endpoint policy-gated or user-scoped), no mass assignment (no `$request->all()` into create/update anywhere), no SQL injection (~60 raw-SQL sites, all literals with bound params), XSS double-sanitized (DOMPurify client + HTMLPurifier server), open-redirect handled by `safeRedirect`, CORS an explicit allow-list, security headers + HSTS + `default-src 'none'` CSP for the JSON API, Filament gated by `canAccessPanel` + MFA enforcement.

**Findings.** C1 (payment, fixed-unvalidated), H7 (token expiry), M1 (signed-URL ownership), M9 (rate-limit gaps), M10 (`can()` under Sanctum), L1/L2/L7.

**Risks.** Security: one critical, several mediums — none of the systemic classes (injection, IDOR, mass-assignment) are present, which is the important negative result.

**Recommendations.** Validate the C1 fix first, then token expiry, then webhook throttle, then signed-URL owner checks. **Effort: 2–3 days** (excluding C1, already done).

## 11. Authentication

**Current State.** Sanctum bearer tokens fronted by an httpOnly/Secure/SameSite BFF cookie; password reset revokes all tokens and devices; logout revokes the presented token with a bearer fallback; login throttled and account-locking; MFA enroll/verify present and tested; email/phone OTP.

**Findings.** Tokens never expire (H7); `mfa/disable` and `verify-phone` routes exist but are unreachable from the UI and untested (L10); register/password rate-limits key on IP alone (M9).

**Risks.** Security: indefinite token lifetime.

**Recommendations.** Set expiration + `sanctum:prune-expired`; decide whether MFA-disable ships or is removed. **Effort: 0.5–1 day.**

## 12. Authorization

**Current State.** The strongest single area. Layered role → permission → ownership; `InstructorController::ownedCourse` centralizes `isTrainedBy`; admin routes gate in-method (`Gate::authorize`) even though the route layer only requires `auth:sanctum`; instructor scoping centralized in one gate reached via the parent course; analytics permission-gated with instructor refusal; 404-not-403 to avoid existence disclosure. The `Actor::hasPermission()` guard-pinning fix from earlier steps is the correct pattern.

**Findings.** Only M10 — two policies still use `can()` and thus fail closed for non-super-admins. No escalation paths found.

**Risks.** Low. M10 is a functionality bug (features unreachable) more than a security hole.

**Recommendations.** Convert the two policies to `hasPermission()`. **Effort: <0.5 day.**

## 13. Observability

**Current State.** Sentry wired (no-op without DSN); Horizon dashboards; `/api/v1/health/live` (dependency-free) and `/health/ready` (Postgres+Redis, 503 on failure, no detail leakage); `/up` framework liveness; an `uptime.yml` workflow.

**Findings.** The dead-letter/failed-job feedback loop is missing (H5) — nothing watches `failed_jobs` or dead deliveries. No metric on notification outcomes. Audit-log infrastructure exists (`AuditLog` model) but coverage wasn't audited here.

**Risks.** Production: failures happen silently and evidence is pruned on a timer.

**Recommendations.** Alert on `failed_jobs` growth and dead-letter events; surface both in Filament. **Effort: 1 day.**

## 14. Logging

**Current State.** Structured JSON to stdout via `JsonFormatter`, with `CorrelationProcessor` and `PsrLogMessageProcessor`; correlation-id middleware on requests.

**Findings.** Correlation id doesn't reach queued work (M2); the notification path logs nothing (M3); plaintext OTP logged in local (L7). The *infrastructure* is right; the *coverage* has holes exactly where async failures occur.

**Risks.** Maintainability: async incidents are the hardest to debug and the least logged.

**Recommendations.** Propagate correlation id into jobs (`Queue::createPayloadUsing` or a job property); add delivery-path logging. **Effort: 1 day.**

## 15. Error Handling

**Current State.** Domain exceptions render themselves to the standard envelope; the `AuthenticationException` override prevents 500s on the API surface; a rich set of typed domain exceptions exists.

**Findings.** ~13 of those typed exceptions are never thrown (L8) — either the error paths that should throw them swallow errors, or they're speculative. The export `catch` re-throws after marking `Failed`, which is correct, but the timeout path (C4) bypasses it entirely.

**Risks.** Low-medium. Unused exceptions suggest error paths that degrade silently instead of throwing.

**Recommendations.** Spot-check whether the unused exceptions represent missing error handling vs dead code before deleting. **Effort: 0.5 day (analysis).**

## 16. Infrastructure

**Current State.** Dockerfiles for both apps (referenced and built in CI); `infra/php` config (memory_limit 256M, opcache); trusted-proxy/host handling for ALB/CloudFront; deploy and uptime workflows; ops runbooks (deployment, DR, incident response, monitoring, rollback, secrets) under `docs/ops/`.

**Findings.** **Not fully verifiable from static review** — the reviewer cannot run containers or inspect the live cluster. The **CLI `memory_limit` is not loading `infra/php/php.ini`**: the Pest suite OOMs at 128M and needs `php -d memory_limit=1G`, and PHPStan needs `--memory-limit=1G`. That's a real container-config gap that will bite CI or a cron export. Trusted-proxy defaults to `*` (acceptable behind a controlled LB, risky if ever exposed).

**Risks.** Production: the CLI memory gap can fail scheduled exports/reports silently.

**Recommendations.** Ensure the CLI SAPI loads the tuned `php.ini`; pin `TRUSTED_PROXIES` in production. **Effort: 0.5 day.** *Full infra audit requires cluster access this review did not have — flagged, not assessed.*

## 17. CI/CD

**Current State.** A genuinely strong pipeline (`ci.yml`): API job (Pint, PHPStan blocking, composer audit blocking, migrate, Pest), Deptrac architecture job, Web job (npm audit prod-deps blocking, ESLint, tsc, vitest, build), gitleaks secret scan, Playwright smoke+a11y (mock API, no external backend), and image build+Trivy scan (CRITICAL/HIGH blocking, one documented `.trivyignore` exception) pushing to GHCR on main/tags. Concurrency cancellation, composer/npm caching, ADR-link validation workflow.

**Findings.** Rector and Deptrac are installed on-the-fly with `continue-on-error`/fallback rather than pinned in `require-dev` — a supply-chain and reproducibility smell. Visual-regression is correctly excluded as advisory. The authenticated e2e journeys are skipped (no auth fixture), so CI's a11y coverage is public surfaces only — the instructor dashboard is not exercised in CI.

**Risks.** Low. This is the healthiest area after authorization.

**Recommendations.** Pin Deptrac/Rector in `require-dev`; add an e2e auth fixture so the dashboard enters CI. **Effort: 0.5–1 day.**

## 18. Documentation

**Current State.** Extensive and unusually accurate — 137 files under `docs/` (ADRs CI-enforced and matching code, ops runbooks, design system, sprint reports), plus root status/release docs. Zero removed-tech drift (no LearnHouse/NestJS/FastAPI references anywhere).

**Findings.** `docs/README.md` describes an empty folder that now holds 137 files; `VERSION` disagrees with release docs (M11); `PROJECT_STATUS.md` vs `FINAL_PROJECT_STATUS.md` overlap without cross-reference; product naming inconsistent (HElbaron / CoreLMS / Helbaron LMS); `apps/api/docs/` is an empty scaffold. No API reference doc was found (OpenAPI/collection) — **not verifiable that one is absent vs elsewhere**, flagged.

**Risks.** Maintainability: version ambiguity feeds build tooling a wrong number.

**Recommendations.** Reconcile `VERSION`/`README`/`CHANGELOG`; rewrite `docs/README.md`; pick one product name. **Effort: 0.5 day.**

## 19. Technical Debt

Consolidated below under its own heading.

## 20. Dead Code

**Current State.** Low overall. High-confidence dead: ~16 Actions/Services (incl. Catalog `CreateCourseAction`/`UpdateCourseAction` — suggests the Filament resource does the work inline, contra ADR-04), 4 unused models, 3 unused traits, ~13 unused exceptions, several frontend hooks/functions, ~40 orphan i18n keys, one dead full-stack teach-dashboard chain.

**Findings.** Carefully distinguished from *dynamically resolved* code (commands, factories, seeders, policies, Filament resources) and from *honest architectural scaffolding* (Tenancy/Integration contracts explicitly marked "Not started" in ADRs — not rot).

**Risks.** Maintainability only.

**Recommendations.** Delete the teach-dashboard chain (clean, self-contained); batch-delete exceptions/traits/Actions last, re-running PHPStan/Deptrac after each batch. **Effort: 1–2 days**, low urgency.

---

# Technical Debt

**Architecture.**
- `WorkflowEngine`/`DigestService`/`ScheduledAutomation` built and migrated but unwired — decide ship-or-defer.
- `InstructorAnalyticsService` bypasses `EnrollmentStatsPort` despite the port existing (violates the port's own "only place" claim).
- Catalog `CreateCourseAction`/`UpdateCourseAction` dead while the Filament resource presumably inlines the logic (ADR-04 tension).
- Cache keys lack a tenant discriminator ahead of multi-tenancy activation.

**Backend.**
- Notification dedup non-functional; delivery claiming non-atomic.
- Duplicated enrollment-aggregate SQL (×3) and rate arithmetic (×6).
- ~13 unused typed exceptions (possible missing error handling).
- Unpaginated list endpoints (×12).

**Frontend.**
- Dead teach-dashboard chain (route→client→hook→type).
- ~40 orphan i18n keys; no parity test on the global dictionary.
- No Storybook stories / no browser-level test for the new dashboard.
- Filters not URL-synced; `t()` lacks interpolation.

**Security.**
- Token expiry unset; signed-URL ownership missing; two policies fail closed via `can()`; public surface partly unthrottled; register/password rate-limits IP-only.

**Performance.**
- Systemic FK-index gap; `whereDate` non-sargable filters; learner-player N+1; retention OOM; export streamed through memory.

**Infrastructure.**
- CLI `php.ini` not loading tuned memory_limit; Deptrac/Rector not pinned in `require-dev`; trusted-proxy `*` default.

**Documentation.**
- `VERSION` mismatch; stale `docs/README.md`; overlapping status docs; naming inconsistency; API reference presence unverified.

---

# Quick Wins (< 1 day each)

1. Validate the C1 payment fix by running the backend gates (**do this first**).
2. Set Sanctum token expiry + schedule `sanctum:prune-expired` (H7).
3. Throttle `POST /payment/webhook` and the other public GETs (M9).
4. Convert `LiveSessionPolicy`/`CertificatePolicy` from `can()` to `hasPermission()` (M10).
5. Reconcile `VERSION`/`README`/`CHANGELOG`; rewrite `docs/README.md` (M11).
6. Add the FK/date index migration (H1) — writing is <1 day; review the plans separately.
7. Fix the CLI `php.ini` memory_limit loading (Infra).
8. Delete the dead teach-dashboard chain (Frontend/Dead code).
9. Add a global-dictionary parity test (locks in the current zero-drift).
10. Add owner checks inside the signed-file handlers (M1).

# Major Projects (multi-day initiatives)

1. **Notification pipeline hardening** — atomic claiming, real dedup, dead-letter alerting, channel truthfulness, logging, missing endpoints. 3–5 days. *Release-relevant.*
2. **Async resilience** — queue the listeners, `ShouldBeUnique` on jobs, reminder-dispatch command, correlation-id propagation. 3–4 days.
3. **Analytics scalability** — SQL pagination/sort, caching, `retention()` rewrite, aggregate centralization. 3–4 days.
4. **Performance pass** — indexes + learner-player batching + instructor-list swap + `whereDate`→range + export streaming. 3–4 days.
5. **Automation engine decision** — either wire `WorkflowEngine`/`DigestService`/`ScheduledAutomation` into a real feature or explicitly defer and mark them scaffolding. Scope depends on product intent — **1–2 weeks if built**, 0.5 day if formally deferred.
6. **E2E auth fixture + dashboard in CI** — un-skip authenticated journeys, regenerate visual baselines. 1–2 days.

---

# Release Blockers

Only items that genuinely prevent a production release:

1. **C1 — payment webhook bypass.** Fixed but **unvalidated**; a payment auth bypass cannot ship on the word of an untested diff. *Blocker until Pest confirms.*
2. **C2 + C3 — notification loss and double-send.** A messaging system that silently drops rate-limited messages and double-sends on retry is not shippable for anything transactional (enrollment, payment, certificate notifications). *Blocker.*
3. **H6 — channels that report false delivery.** `WhatsAppChannel`/unwired channels marking `Sent` corrupts the delivery ledger. If any non-in-app channel is enabled in production this is a blocker; if the product ships in-app-only for v1, downgrade to High and gate the other channels off. *Conditional blocker.*

Everything else is High or below and can ship with a documented known-limitation.

**Note:** C4 (export hang) is fixed-unvalidated and severe, but exports are an admin convenience, not a core purchase/learn path — High, not a hard blocker, once validated.

# Nice-to-Have Improvements (explicitly NOT blockers)

- Dead-code deletion, i18n orphan cleanup, Storybook stories.
- URL-synced filters, `t()` interpolation.
- Aggregate/rate centralization (maintainability, not correctness).
- MFA-disable / verify-phone completion.
- Over-eager catalog loading, funnel query collapse.
- Documentation reconciliation beyond the `VERSION` fix.
- Pinning Deptrac/Rector in `require-dev`.

---

# Recommended Execution Order (dependency-aware)

**Phase 0 — Validate what's already done (0.5 day).**
Run backend gates against the C1/C4 fixes from the prior execution. Nothing below is trustworthy until the baseline is green again. If C1 fails validation, it returns to the top of Phase 1.

**Phase 1 — Release blockers (Step 9 security + Step 6 core) (4–6 days).**
1. Confirm/repair C1; rotate `COMMERCE_WEBHOOK_SECRET`; audit prod orders for unmatched charges.
2. Notification pipeline hardening (C2, C3, H5, H6) — atomic claim, real dedup, dead-letter alerting, channel truthfulness. This is the critical path; it has no dependency on the performance work and should start immediately in parallel with (1).
3. Quick-win security items (H7 token expiry, M9 webhook throttle, M10 policies).

**Phase 2 — Async resilience + observability (Step 6 automation) (3–4 days).**
Depends on Phase 1's notification fixes landing (same files). Queue the listeners, add `ShouldBeUnique`, reminder-dispatch command, correlation-id propagation (M2), delivery-path logging (M3). Wire the dead-letter/failed-job alerts (H5) — this closes the observability loop the blockers opened.

**Phase 3 — Database + performance (Step 8) (3–4 days).**
Index migration first (H1 — everything downstream benefits), then learner-player batching (H2), instructor-list swap (H3), `whereDate`→range (M5), export streaming (M8). Independent of Phases 1–2; can run in parallel with a second engineer.

**Phase 4 — Analytics + caching (Step 7) (3–4 days).**
Depends on Phase 3's indexes for the query rewrites to be worth measuring. SQL pagination/sort (H8), cache extension + discriminators (Caching), `retention()` rewrite (C5), aggregate centralization (M6). H4 (async announcement fan-out) lands here since it reuses Phase 2's queued-listener pattern.

**Phase 5 — Automation-engine decision (Step 6 tail) (0.5 day to 2 weeks).**
Product decision gate: build or formally defer `WorkflowEngine`/`DigestService`/`ScheduledAutomation`. Do NOT let this block the RC — defer-and-document is a valid Phase 5 in 0.5 day.

**Phase 6 — Cleanup + docs + CI (pre-RC polish) (2–3 days).**
Dead-code deletion (re-run PHPStan/Deptrac per batch), i18n parity test, `VERSION` reconciliation, e2e auth fixture + dashboard in CI, visual-baseline regeneration, CLI memory_limit fix.

**Gate to Release Candidate:** all release blockers closed and validated; full backend gates (Pint/PHPStan/Deptrac/Pest) and frontend gates (ESLint/tsc/Vitest/build/Storybook) green; authenticated e2e passing in CI; the automation engine either shipped or documented as deferred in `KNOWN_LIMITATIONS.md`.

---

*This audit is static-analysis only. Claims about test/gate outcomes are inherited from the last host run and not re-verified here. Infrastructure and live-cluster behavior were assessed only insofar as they are visible in committed configuration; a runtime infra review requires cluster access this review did not have.*
