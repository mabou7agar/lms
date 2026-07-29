# Sprint Execution Plan

**Authoritative source:** `PRODUCTION_EXECUTION_PLAN.md` (which traces to `STEP_6_TO_9_ENGINEERING_AUDIT.md`). Every sprint below draws only from that backlog by ID (C1–C5, H1–H9, M1–M11, L1–L11, NV1–NV5). No new work is introduced.

**Governing principle:** minimize regression risk. Each sprint is small (3–6 backlog items or 3–5 engineering days, whichever comes first), single-domain, and independently implemented → validated → merged → documented before the next begins. **Every sprint leaves `main` in a releasable state.** No long-lived branches: each sprint is one short-lived feature branch merged behind green gates, then tagged.

**Standing constraint inherited from the plan:** three prior-execution fixes (C1, C4, and 5 webhook tests) are written but unvalidated. Sprint 0 exists to resolve that before anything is built on top.

---

## Global Merge Policy (applies to every sprint)

```
implement on sprint/<n>-<slug>  →  run full validation
  ├─ GREEN → merge to main → tag rc-sprint-<n> → document → start next sprint
  └─ RED   → STOP. fix on the same branch. re-run validation. do not proceed.
```

- One short-lived branch per sprint, rebased on `main`, deleted after merge. No sprint branch outlives its sprint.
- "Validation" always means: backend gates (Pint, PHPStan L6, Deptrac, Pest) **and** frontend gates (ESLint, tsc, Vitest, build, Storybook) — even for backend-only sprints, to prove no cross-app contract broke. Sprint-specific integration/manual/regression items are added per sprint.
- A sprint is not "done" until its Definition of Done is fully met **and** a one-paragraph completion note is appended to `PROJECT_STATUS.md` (or the team's changelog).
- Backend gates must pass at the container's default memory limit where NV1 (CLI memory) has been fixed; until Sprint 9 fixes it, gates run with `php -d memory_limit=1G` and that override is noted as a temporary condition.

---

## Sprint Map (ordered by risk: security → money → notifications → async → infra → performance → analytics → cleanup → docs)

| # | Sprint | Domain | Items | Days | Risk | On critical path? |
|---|---|---|---|---|---|---|
| 0 | Baseline Validation | Gates | C1✓, C4✓ (validate) | 0.5 | Low | **Yes** |
| 1 | Payment Integrity | Money/Security | C1, M9(webhook), NV(secret rotation) | 2 | High | **Yes** |
| 2 | Access-Control Hardening | Security/Auth | H7, M10, M1 | 2 | Medium | No |
| 3 | Notification Correctness | Notifications | C2, C3, H6 | 4 | High | **Yes** |
| 4 | Notification Observability | Notifications/Obs | H5, M2, M3 | 3 | Medium | **Yes** |
| 5 | Async Resilience | Automation/Queues | C4, M4, H4, H9 | 4 | Medium | No |
| 6 | Database Indexing | Database | H1, M5 | 2 | Medium | No |
| 7 | Hot-Path Performance | Performance | H2, H3, M8 | 3 | Medium | No |
| 8 | Analytics Scale & Cache | Analytics/Caching | C5, H8, M6 | 4 | Medium | No |
| 9 | Infra & CI | Infrastructure/CI | NV1, CI(auth fixture), M7 | 3 | Low | No |
| 10 | Cleanup | Dead code | L2, L4, L5, L8, L9 | 3 | Low | No |
| 11 | Docs & Release Prep | Documentation | M11, L1, L11, Phase-5 decision | 2 | Low | **Yes (RC gate)** |

**Total: 12 sprints, ~32.5 engineering days (~6.5 calendar weeks single-threaded; ~3–3.5 weeks with the parallel track).**

Deliberately **excluded** from sprints (carried as spikes, not scheduled work, per the plan): NV2–NV5 investigation, the automation-engine *build* (only its go/defer decision is in Sprint 11), L3/L6/L7/L10 (folded into Sprint 5 or 10 only if capacity allows; otherwise documented known-limitations). L10 (MFA-disable) is a product decision, not scheduled.

---

# Sprint 0 — Baseline Validation

**Goal.** Prove the repository is actually green before building on it. Resolve the unvalidated C1/C4 fixes from the prior execution.

**Backlog IDs.** C1 (validate only), C4 (validate only).

**Why these belong together.** Both are already-written fixes awaiting a gate run; neither is new implementation. They are the precondition for every later sprint.

**Dependencies.** None. Blocks everything.

**Repository areas affected.** None (validation only) — unless a gate fails, in which case the failure is repaired here.

**Estimated effort.** 0.5 day.

**Risk level.** Low — but the highest-leverage 0.5 day in the plan.

**Validation required.**
- Backend gates: Pint, PHPStan, Deptrac, Pest (incl. the 5 `WebhookSignatureTest` cases and any export-job test).
- Frontend gates: ESLint, tsc, Vitest, build, Storybook.
- Manual: none.
- Regression checklist: full existing suite passes; Deptrac baseline unchanged (164); PHPStan error count 0.

**Rollback strategy.** If a prior fix fails validation, revert that single fix to the last green commit and re-open it as the first item of Sprint 1. No partial state ships.

**Merge strategy.** If already on `main` and green, no merge needed — tag `rc-sprint-0-baseline`. If a repair was required, merge the repair behind green gates.

**Definition of Done.** All gates green on the host; C1 and C4 fixes confirmed by passing tests; baseline tagged.

---

# Sprint 1 — Payment Integrity

**Goal.** Close the payment auth-bypass release blocker and its operational tail so no order can be marked paid without a verified charge.

**Backlog IDs.** C1 (confirm hardening + production guard), M9 (webhook throttle only), plus the operational riders the plan attaches to C1: rotate `COMMERCE_WEBHOOK_SECRET`, audit production orders for unmatched charges.

**Why these belong together.** All three are the same attack surface — the public payment webhook. Throttling it (M9 subset) and rotating its secret are the same incident-response envelope as the signature fix. Nothing else in M9 (the other public GETs) is included here; those go to Sprint 2 to keep this sprint money-only.

**Dependencies.** Sprint 0 green.

**Repository areas affected.** `Contexts/Commerce/Payments/*`, `Contexts/Commerce/routes/commerce.php`, rate-limiter config. Ops: secret store.

**Estimated effort.** 2 days.

**Risk level.** High (money path) — mitigated by being small and test-first.

**Validation required.**
- Backend gates (all).
- Integration tests: unsigned / wrong / empty / replayed / valid webhook (the 5 existing cases must stay green); throttle test on the webhook route.
- Manual verification: confirm `COMMERCE_PAYMENT_PROVIDER` guard throws when `fake` in a production-like env; confirm rotated secret validates.
- Regression checklist: existing Commerce suite (cart, checkout, refund, fulfillment gating) unchanged; no other route's throttle behavior altered.

**Rollback strategy.** Single-domain revert of the Commerce changes returns to Sprint 0 baseline; the webhook remains fail-closed because the prior-execution fix is already merged, so rollback never re-opens the bypass.

**Merge strategy.** One branch `sprint/1-payment-integrity`; merge behind green gates; tag `rc-sprint-1`.

**Definition of Done.** Webhook rejects every invalid signature form and is throttled; production cannot resolve the fake gateway; secret rotated; prod-order audit query run and results recorded. Repository releasable.

---

# Sprint 2 — Access-Control Hardening

**Goal.** Close the standing security items that are not money-path: token lifetime, the fail-closed policies, signed-URL ownership, and the remaining public-surface throttling.

**Backlog IDs.** H7 (token expiry + pruning), M10 (`can()` → `hasPermission()` in the two policies), M1 (signed-URL owner checks), M9 (remainder — public GET + register/password keying).

**Why these belong together.** All are authorization/authentication hardening touching guards, policies, and rate-limiter config — one reviewer's mental model, no domain mixing with Commerce or Notifications.

**Dependencies.** Sprint 0. Independent of Sprint 1 (different files) — *could* run in parallel, but sequenced here to keep security review focused.

**Repository areas affected.** `config/sanctum.php`, `routes/console.php` (prune schedule), `LiveSessionPolicy`, `CertificatePolicy`, export + certificate file controllers, rate-limiter provider, various public route files.

**Estimated effort.** 2 days.

**Risk level.** Medium — M10 changes who can reach live-session and certificate actions; needs a positive-path test to prove it opens access correctly rather than breaking it.

**Validation required.**
- Backend gates (all).
- Integration tests: a permitted non-super-admin can now manage a live session and revoke/reissue a certificate (M10); an owner-mismatched signed URL returns 403 (M1); token expiry honored + prune command test (H7); throttle tests on the newly-covered public routes (M9).
- Manual: confirm `sanctum:prune-expired` scheduled.
- Regression checklist: super-admin bypass unchanged; existing auth/login/MFA suite green; no public endpoint that should stay open is now blocked.

**Rollback strategy.** Per-file revert; each change is independent, so a single failing item can be dropped without affecting the others.

**Merge strategy.** `sprint/2-access-control`; green-gate merge; tag `rc-sprint-2`.

**Definition of Done.** Tokens expire and prune; two policies reachable by permission holders; signed files owner-checked; public surface throttled. Releasable.

---

# Sprint 3 — Notification Correctness

**Goal.** Close the notification release blocker: stop silent message loss, stop double-sends, stop the ledger lying about delivery.

**Backlog IDs.** C2 (rate-limit no longer dead-letters), C3 (real dedup + unique index), H6 (channels mark `Failed`/`Skipped` not `Sent`; v1 channel scope enforced).

**Why these belong together.** All three live in the same delivery pipeline (`DeliverNotificationJob`, `NotificationDispatcher`, channel resolution) and the same migration surface. Splitting them would create merge churn in shared files and risk a half-fixed pipeline shipping.

**Dependencies.** Sprint 0. Independent of Sprints 1–2. **On the critical path.**

**Repository areas affected.** `Platform/Notifications/Jobs/DeliverNotificationJob`, `Services/NotificationDispatcher`, `Channels/*`, `ChannelManager`, a migration adding the `dedup_key` unique index. Requires a **product decision** on which channels ship in v1 (in-app-only vs wired channels) — this is the H6 gate and must be answered before the sprint closes.

**Estimated effort.** 4 days.

**Risk level.** High — the pipeline is transactional-message infrastructure; a regression here is invisible until users don't get messages.

**Validation required.**
- Backend gates (all).
- Integration tests: rate-limited delivery is deferred and eventually sent, never dead-lettered for rate-limiting (C2); dispatching the same domain event twice yields exactly one notification + one send (C3); an unwired channel records `Failed`/`Skipped`, never `Sent` (H6); migration test for the unique constraint.
- Manual: run a small fan-out locally and inspect the delivery ledger for truthfulness.
- Regression checklist: existing notification suite (delivery resilience, event delivery, notification center) green; in-app notifications unaffected; localization/template fallback unchanged.

**Rollback strategy.** The dedup unique-index migration must be independently reversible (down migration). If the sprint fails late, revert the code and roll back the migration together; the pipeline returns to its prior (flawed but known) behavior rather than a broken intermediate.

**Merge strategy.** `sprint/3-notification-correctness`; green-gate merge; tag `rc-sprint-3`. Do not merge partially — all three items or none.

**Definition of Done.** No message lost to rate-limiting; no duplicate on retry; ledger reflects reality; v1 channel scope decided and enforced. Releasable.

---

# Sprint 4 — Notification Observability

**Goal.** Make notification failures visible now that they are correctly generated — close the observability loop the correctness fixes opened.

**Backlog IDs.** H5 (dead-letter + failed-job alerting + Filament widget), M2 (correlation id into queued work), M3 (delivery-path structured logging).

**Why these belong together.** All three are observability of the same pipeline, and H5's alerting is only meaningful once Sprint 3 stops producing false dead-letters. M2/M3 make the alerts actionable (a dead-letter alert with a correlation id and a log trail is debuggable; without them it isn't).

**Dependencies.** **Sprint 3 must land first** — this is the one hard sequential link inside the notification track.

**Repository areas affected.** `Platform/Notifications/*` (event subscribers, logging), a Filament widget, `app/Logging/*` / queue payload hook, `AssignCorrelationId` middleware coordination.

**Estimated effort.** 3 days.

**Risk level.** Medium — additive; low chance of functional regression, but the correlation-id propagation touches the queue payload path and needs a test.

**Validation required.**
- Backend gates (all).
- Integration tests: a dead-letter event triggers the alert path and increments the widget count (H5); a queued job's log line carries the originating request's correlation id (M2); send/retry/dead-letter each emit a structured log line (M3).
- Manual: view the Filament widget with seeded failures.
- Regression checklist: existing logging config unchanged for request-scoped logs; Horizon dashboards unaffected.

**Rollback strategy.** Fully additive — revert restores the prior (silent) behavior with no data-shape change. No migration.

**Merge strategy.** `sprint/4-notification-observability`; green-gate merge; tag `rc-sprint-4`.

**Definition of Done.** Dead-letter and failed-job growth alerted and visible in Filament; correlation id present in job logs; delivery path logged. Releasable.

---

# Sprint 5 — Async Resilience

**Goal.** Remove the request-timeout failure class and finish the export/reminder job resilience.

**Backlog IDs.** C4 (export queue/timeout/failed — validate + finalize), M4 (queue heavy listeners), H4 (queued + chunked announcement fan-out), H9 (scheduled reminder consumer).

**Why these belong together.** All are background-job resilience: routing, queuing synchronous work, and adding the missing scheduled consumer. They share the queue/scheduler mental model and the `ShouldQueue`/idempotency pattern. H4 explicitly reuses the queued-listener approach from M4.

**Dependencies.** Sprint 3 (H4 fan-out uses the corrected notification pipeline). Not on the critical path — can run in parallel with Sprints 6–7 by a second engineer once Sprint 3 lands.

**Repository areas affected.** `Contexts/Analytics/Jobs/ProcessExportJob`, all `app/**/Listeners`, `Domains/Catalog/.../AnnouncementController` + a queued action, `Domains/Live/Reminders/*`, `routes/console.php` (reminder schedule), `config/horizon.php` verification.

**Estimated effort.** 4 days.

**Risk level.** Medium — queuing previously-synchronous listeners changes timing; needs `ShouldHandleEventsAfterCommit` and idempotency tests to avoid double-processing.

**Validation required.**
- Backend gates (all).
- Integration tests: a failed export lands in `failed`, never `processing` (C4); heavy listeners are queued and idempotent (M4); large-cohort announcement fan-out is queued, chunked, resumable (H4); due reminders dispatched exactly once by the scheduled command (H9).
- Manual: run the reminder command against seeded pending reminders.
- Regression checklist: `after_commit=true` respected; existing event/listener behavior preserved (registration, certificate generation, analytics rollup still fire); Horizon `exports` supervisor receives the export job.

**Rollback strategy.** Listener queuing is revertible per-listener. The reminder command is additive (revert = feature simply dormant again). No destructive migration.

**Merge strategy.** `sprint/5-async-resilience`; green-gate merge; tag `rc-sprint-5`.

**Definition of Done.** No synchronous heavy listeners; exports fail loudly; fan-out queued; reminders delivered idempotently. Releasable.

---

# Sprint 6 — Database Indexing

**Goal.** Land the systemic index migration and remove the non-sargable filters that would defeat it — the foundation every later performance/analytics sprint measures against.

**Backlog IDs.** H1 (FK/date index migration), M5 (`whereDate` → half-open range).

**Why these belong together.** M5 exists specifically because `whereDate` defeats the indexes H1 adds; doing them apart means H1's benefit is invisible on the affected queries. Both are pure query-plan work, no behavior change.

**Dependencies.** Sprint 0. Independent of all notification/async work — **the natural start of the parallel second-engineer track.** Should land before Sprints 7 and 8 so their speed claims are measurable.

**Repository areas affected.** One additive index migration across enrollments/orders/order_items/product_courses/course_trainer/certificates/lesson_progress/courses; `EnrollmentStatsAdapter`, `CoursePerformanceService` (range filters).

**Estimated effort.** 2 days (writing < 1 day; the rest is `EXPLAIN` review on a data copy).

**Risk level.** Medium — index migrations on large tables can lock; must be reviewed for concurrent-index creation and run on a staging copy first.

**Validation required.**
- Backend gates (all).
- Integration tests: existing query-count/behavior tests unchanged; no result-set changes from the `whereDate`→range swap (boundary tests at day edges).
- Manual/DevOps: run migration on a staging DB copy; capture `EXPLAIN` on the named hot queries showing index use; confirm no long lock.
- Regression checklist: date-boundary results identical before/after M5; all reports return the same rows.

**Rollback strategy.** Down migration drops the indexes (safe, non-destructive). M5 is a pure code revert. Because indexes are additive, rollback never changes correctness — only speed.

**Merge strategy.** `sprint/6-db-indexing`; green-gate merge; tag `rc-sprint-6`. Coordinate the production migration as a separate, monitored deploy step (documented in the release checklist), not silently on merge.

**Definition of Done.** Indexes applied; named queries use them per `EXPLAIN`; `whereDate` filters sargable; date-boundary results unchanged. Releasable.

---

# Sprint 7 — Hot-Path Performance

**Goal.** Fix the two query-count offenders on the most-used surfaces and stop streaming exports through memory.

**Backlog IDs.** H2 (learner player N+1), H3 (instructor list → batched service), M8 (export streaming).

**Why these belong together.** All three are read-path performance with query-count/memory regression tests as their acceptance criteria; H2/H3 both reuse already-loaded data or the existing `CoursePerformanceService`. M8 rides along as the export read-path counterpart.

**Dependencies.** Sprint 6 (indexes make H2/H3 improvements measurable and real). Not on the critical path.

**Repository areas affected.** `Contexts/Learning/.../LearnController` + `LessonAccessService`, `Domains/Catalog/.../Instructor/CourseController`, `Contexts/Analytics/.../ExportController`.

**Estimated effort.** 3 days.

**Risk level.** Medium — H2 touches learner access-control resolution; the batching must not change *who* can see *what*.

**Validation required.**
- Backend gates (all).
- Integration tests: query-count regression for the player (constant vs lesson count) and instructor list (constant vs course count); **access-control unchanged** — a learner still cannot see a lesson they lack access to (H2); large export downloads without loading the whole file into memory (M8).
- Manual: load the player on a large seeded course and confirm response time + query count.
- Regression checklist: learner player output identical; instructor list response shape identical; prerequisite locking unchanged.

**Rollback strategy.** Per-endpoint code revert; no schema change. Access-control tests gate the merge, so a rollback never weakens authorization.

**Merge strategy.** `sprint/7-hot-path-perf`; green-gate merge; tag `rc-sprint-7`.

**Definition of Done.** Player and instructor list have bounded query counts with unchanged authorization; exports streamed. Releasable.

---

# Sprint 8 — Analytics Scale & Cache

**Goal.** Make the admin analytics survive production volume: bound the OOM query, cache the expensive endpoints, centralize the duplicated aggregates.

**Backlog IDs.** C5 (retention SQL rewrite), H8 (SQL pagination + caching with discriminated keys), M6 (aggregate/rate centralization).

**Why these belong together.** All are the analytics/reporting subsystem; C5 and H8 are the two scale problems and M6 removes the duplicated calculation logic they both touch, so doing them together avoids editing `ReportingService` three times.

**Dependencies.** Sprint 6 (the rewrites are only worth benchmarking against real indexes). Not on the critical path.

**Repository areas affected.** `Contexts/Analytics/Services/Reports/ReportingService`, `KpiEngine`/cache layer, `EnrollmentStatsPort`/`EnrollmentStats`, a shared rate helper, insight controllers (pagination).

**Estimated effort.** 4 days.

**Risk level.** Medium — caching can serve stale data; keys must be discriminated and invalidated, and `total` must stay accurate under SQL pagination.

**Validation required.**
- Backend gates (all).
- Integration tests: retention returns correct cohorts at seeded volume without OOM (C5); insight endpoints paginate/sort in SQL with accurate `total` (H8); cache-hit test + key-discriminator test (no cross-user/cross-tenant bleed) + invalidation on snapshot write (H8); aggregate values identical after routing through the shared calculators (M6).
- Manual: refresh an insight dashboard twice; confirm the second load is cached.
- Regression checklist: every report returns the same numbers as before centralization; instructor dashboard (Step 5) still renders under cached data.

**Rollback strategy.** Caching is additive (revert = uncached but correct). C5/M6 are code reverts. No destructive migration. Because cache keys are new, rollback cannot corrupt existing data.

**Merge strategy.** `sprint/8-analytics-scale`; green-gate merge; tag `rc-sprint-8`.

**Definition of Done.** Retention bounded; insight endpoints cached + SQL-paginated; one calculator per metric. Releasable.

---

# Sprint 9 — Infrastructure & CI

**Goal.** Fix the proven infra gap, bring the authenticated dashboard into CI, and paginate the remaining unbounded endpoints.

**Backlog IDs.** NV1 (CLI `php.ini` memory_limit — the one *proven* infra item), CI (e2e auth fixture + authenticated dashboard in CI), M7 (paginate remaining collection endpoints).

**Why these belong together.** All are release-infrastructure: making the toolchain and CI trustworthy, and closing the last API-shape gap so the pipeline exercises real bounded responses. Grouping M7 here means the new pagination is immediately covered by the strengthened CI.

**Dependencies.** Sprints 1–8 landed (so the dashboard CI exercises the final code). Not on the critical path until the RC gate.

**Repository areas affected.** `infra/php/*`, `.github/workflows/ci.yml`, Playwright e2e support (auth fixture), ~12 list controllers (M7), contract tests.

**Estimated effort.** 3 days.

**Risk level.** Low — CI and config, plus additive pagination. M7 changes response envelopes, so frontend consumers of those endpoints need a contract check.

**Validation required.**
- Backend gates at the container's **default** memory limit (NV1 success criterion — Pest passes without `-d memory_limit`).
- Frontend gates + Playwright smoke + axe including the **authenticated instructor dashboard**.
- Integration tests: each M7 endpoint returns a paginated envelope; frontend callers updated and green.
- Manual: confirm CI runs the authenticated journey.
- Regression checklist: no endpoint that was already paginated changed; dashboard renders in-browser for the first time in CI.

**Rollback strategy.** CI/config revert is safe. M7 is per-endpoint revertible; the contract tests gate it, so a rollback never ships a mismatched envelope.

**Merge strategy.** `sprint/9-infra-ci`; green-gate merge; tag `rc-sprint-9`.

**Definition of Done.** Pest green at default memory; authenticated dashboard in CI; remaining lists paginated. Releasable.

---

# Sprint 10 — Cleanup

**Goal.** Remove dead code and lock in i18n parity now that the real changes have settled — lowest-risk work, done late to minimize merge conflicts.

**Backlog IDs.** L2 ($guarded models), L4 (template lookup cache), L5 (over-eager catalog loading), L8 (dead exceptions/traits/Actions + the dead teach-dashboard full-stack chain), L9 (orphan i18n keys + global parity test). *(L3, L6, L7 folded in if capacity allows.)*

**Why these belong together.** All are maintainability cleanup with no behavior change; batching them into one late sprint avoids interleaving deletions with functional work (where they'd cause churn) and lets PHPStan/Deptrac re-run per deletion batch catch anything a removal breaks.

**Dependencies.** All functional sprints (1–9) landed — delete last so nothing still-referenced is removed.

**Repository areas affected.** Scattered: dead symbols across `app/`, the teach-dashboard chain (route→client→hook→type), `dictionaries.ts`, catalog search service, notification template renderer.

**Estimated effort.** 3 days.

**Risk level.** Low — but deletions can surprise; re-run static analysis after **each** batch, not once at the end.

**Validation required.**
- Backend gates after each deletion batch (PHPStan/Deptrac catch dangling references).
- Frontend gates + the new global-dictionary parity test (L9).
- Integration tests: dead teach-dashboard chain removal doesn't break the live dashboard (which uses the newer hooks).
- Regression checklist: full suite green; no symbol deleted that a dynamic resolver (Filament, factory, seeder, policy) needs — verify against the audit's "DO NOT DELETE" list.

**Rollback strategy.** Each deletion batch is an independent commit; a surprising break reverts just that batch. Low blast radius by construction.

**Merge strategy.** `sprint/10-cleanup`; green-gate merge; tag `rc-sprint-10`.

**Definition of Done.** No dead symbols per static analysis; teach-dashboard chain gone; i18n parity test passing and locked. Releasable.

---

# Sprint 11 — Docs & Release Prep

**Goal.** Reconcile versioning, close documentation drift, regenerate visual baselines, and force the automation-engine go/defer decision — then run the RC gate.

**Backlog IDs.** M11 (VERSION/README/CHANGELOG reconciliation), L1 (`.env.example` APP_DEBUG=false), L11 (regenerate about/contact visual baselines after human review), **Phase-5 decision** (build or formally defer the automation engine into `KNOWN_LIMITATIONS.md`).

**Why these belong together.** All are release-readiness housekeeping and the final gate. The automation-engine decision belongs here because it must be answered before the RC but must not block earlier sprints; the defer option costs 0.5 day.

**Dependencies.** Sprints 0–10 complete.

**Repository areas affected.** `VERSION`, `README.md`, `CHANGELOG.md`, `docs/README.md`, `.env.example`, e2e visual snapshots, `KNOWN_LIMITATIONS.md`.

**Estimated effort.** 2 days (assuming defer; +up to 2 weeks only if the team chooses to build the automation engine — which would then become its own separate sprint series, not part of this one).

**Risk level.** Low.

**Validation required.**
- Full Quality Gates from the execution plan: backend, frontend, infrastructure (Trivy), security (gitleaks + audits), performance (query-count regressions), accessibility (axe incl. authenticated dashboard), browser tests (Playwright incl. authenticated journey), visual regression reviewed.
- Manual: human review of regenerated baselines; confirm version strings agree; confirm automation engine is shipped-and-tested or documented-as-deferred.
- Regression checklist: the full Release Checklist from the execution plan — every item **[✓]** or waived-in-writing; no **[NV]** uninvestigated (NV2–NV5 spikes closed or explicitly deferred with owner).

**Rollback strategy.** Docs/config revert only; baseline regeneration is revertible to the prior snapshots.

**Merge strategy.** `sprint/11-release-prep`; green-gate merge; tag **`v1.0.0-rc`** (the Release Candidate).

**Definition of Done.** The execution plan's 11-point "Production Ready v1.0" Definition of Done is fully met and every item checkable; RC tagged.

---

# Execution Strategy Notes

**Releasable at every step.** Every sprint merges behind full green gates and leaves `main` shippable. If timeline pressure forces a stop after any sprint ≥ 3, the product is in a strictly better state than the start (blockers closed first by design).

**No long-lived branches.** One short-lived branch per sprint, merged within its 2–4 day window. The longest a branch exists is Sprint 3 (4 days).

**Parallelization (per the execution plan's two-engineer model).** Engineer A runs the critical path: Sprint 0 → 1 → 3 → 4. Engineer B runs the independent track: Sprint 2, then Sprint 6 → 7 → 8, then joins for 9–11. Sprint 5 starts once Sprint 3 lands (H4 dependency). This collapses ~6.5 single-threaded weeks to ~3–3.5 calendar weeks. QA validates each sprint's gate; DevOps owns the Sprint 6 production migration and Sprint 9 CI/infra.

**Critical path.** Sprint 0 → Sprint 1 → Sprint 3 → Sprint 4 → Sprint 11 (RC gate). ~11.5 engineering days on the path; everything else (Sprints 2, 5, 6, 7, 8, 9, 10) is off it and parallelizable. Sprint 5's H4 has a soft dependency on Sprint 3 but is not itself on the path.

---

# Priority Ordering Rationale (security → money → notifications → infra → performance → cleanup → docs)

The sprint order is risk-first, exactly as the execution plan mandates:

1. **Validate before building (0).** Never stack work on unproven fixes.
2. **Money and its blast radius first (1).** C1 is active revenue loss on the default config — nothing outranks it.
3. **The rest of security next (2).** Cheap, independent, closes standing exposure before feature work touches the same guards.
4. **Notifications as a two-sprint unit (3 → 4).** Correctness before observability, because alerting on a lying pipeline is worse than no alerting. This is the second release blocker and the bulk of the critical path.
5. **Async resilience (5)** once the notification pipeline it feeds is correct.
6. **Database before the queries that depend on it (6 → 7 → 8).** Indexes first, then the read-path fixes, then the analytics rewrites — each measurable only after the prior lands.
7. **Infra/CI (9)** after the code is final, so CI exercises the real thing.
8. **Cleanup (10)** last among functional-adjacent work, to avoid deletion churn.
9. **Docs and the RC gate (11)** at the end, where the version can finally be stated truthfully and the automation-engine decision made with full runway visibility.

Each boundary is a natural merge-and-tag point where the repository is releasable, which is the entire purpose of sprinting this instead of executing phases wholesale.

---

*Traceability: every sprint's Backlog IDs map to `PRODUCTION_EXECUTION_PLAN.md`, which maps to `STEP_6_TO_9_ENGINEERING_AUDIT.md`. No work is scheduled here that is not in those documents. NV2–NV5 remain spikes; the automation-engine build remains a decision (Sprint 11), not scheduled implementation. Gate-outcome claims inherit the audit's caveat: they require a host run to confirm, which this planning document does not perform.*
