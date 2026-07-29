# Production Execution Plan

**Authoritative source:** `STEP_6_TO_9_ENGINEERING_AUDIT.md`. Every backlog item below traces to a finding in that document by its ID (C1–C5, H1–H9, M1–M11, L1–L11). No work appears here that is not supported by the audit. Items the audit could not prove from the repository are marked **Not Verified**.

**Nature of this document:** a Staff-Engineer-level execution plan, not implementation. No repository files were modified to produce it.

**Standing caveat inherited from the audit:** the reviewer cannot execute PHP or the test suites in this environment. Three fixes from a prior execution — the C1 payment-webhook fix, the C4 export-job fix, and 5 webhook regression tests — are written but **unvalidated**. Phase 0 exists precisely because nothing downstream is trustworthy until the gates run green.

---

# Executive Summary

CoreLMS is a well-architected Laravel 12 + Next.js 15 LMS that passed Step 5 with green gates. It is **not release-ready today.** The defects that matter are concentrated, not diffuse: the payment layer had a live authentication bypass (fixed, unvalidated), the notification pipeline silently drops and duplicates messages, background jobs lack resilience, and a handful of analytics queries and a missing-index gap will not survive production scale.

The most consequential structural fact is that **entire subsystems are built and migrated but wired to nothing** — the automation engine, digest service, scheduled automations, live-session reminders, and multi-channel delivery. In practice **only in-app notifications are ever produced.** This lowers the build effort for Step 6 (scaffolding exists) while raising the risk of shipping a feature that looks finished and is not.

Reaching a defensible Release Candidate is a **2–3 week** effort dominated by two things that must be done well and in order: closing the release blockers (payment validation + notification correctness), then hardening the async and performance layers behind them. Everything else is parallelizable or postponable.

---

# Release Readiness

| Metric | Value | Source |
|---|---|---|
| **Current readiness score** | **72 / 100** | Audit — Overall Production Readiness Score |
| **Target readiness score (RC gate)** | **≥ 90 / 100** | Derived: all blockers closed, async ≥ 75, performance ≥ 80 |
| **Estimated engineering effort** | **2–3 weeks** (excl. automation-engine build) | Audit — Executive Summary + Roadmap |
| **Remaining release blockers** | **3** (one conditional) | Audit — Release Blockers |

**Remaining blockers (verbatim from audit):**
1. **C1** — payment webhook bypass. *Fixed, unvalidated.* Blocker until Pest confirms.
2. **C2 + C3** — notification loss (rate-limited dead-letter) and double-send (decorative dedup). Blocker.
3. **H6** — channels reporting false delivery. *Conditional* — hard blocker only if non-in-app channels ship in v1; otherwise High + gate the channels off.

**Target score rationale:** 90 is the minimum at which the money path (payments), the messaging path (notifications), and the two OOM-class analytics queries are all closed or provably bounded. The remaining 10 points are the nice-to-haves the audit explicitly separates from blockers (dead-code cleanup, URL-synced filters, docs reconciliation) — acceptable to ship as documented known-limitations.

---

# Engineering Backlog

Effort scale: **S** ≤ 1 day, **M** 1–3 days, **L** 3–5 days, **XL** > 1 week. Efforts are the audit's own estimates where given.

Every item carries the fields required: ID, Title, Source, Severity, Business/Technical/User impact, Risk if ignored, Effort, Dependencies, Required validation, Success criteria.

---

## CRITICAL

### C1 — Payment webhook signature bypass
- **Source:** Audit §Critical C1; §Security; §Commerce
- **Severity:** Critical (release blocker)
- **Business impact:** Revenue loss — anyone can obtain paid courses for free.
- **Technical impact:** Public unthrottled endpoint trusts an unsigned payload that flips orders to `Paid` and grants access.
- **User impact:** None visible to legitimate users; direct financial harm to the business.
- **Risk if ignored:** Ongoing free-course fraud on the default configuration.
- **Effort:** S (fix already written)
- **Dependencies:** None
- **Required validation:** Run Pest against the 5 new `WebhookSignatureTest` cases + full backend gates (Pint/PHPStan/Deptrac/Pest). Rotate `COMMERCE_WEBHOOK_SECRET`. Audit production orders for charges with no matching gateway payment.
- **Success criteria:** Unsigned, wrong-signed, empty-signed, and replayed-signature webhooks all return 400 and leave the order `Pending`; correctly signed webhook succeeds; gates green.

### C2 — Rate-limited notifications silently dead-lettered
- **Source:** Audit §Critical C2; §Notifications
- **Severity:** Critical (release blocker)
- **Business impact:** Transactional messages (enrollment, payment, certificate) silently never send.
- **Technical impact:** `DeliverNotificationJob:50` `release(30)` burns an attempt; after `tries=3` message marked `Dead` never having been attempted.
- **User impact:** Users don't receive notifications they expect; no error surfaces.
- **Risk if ignored:** Invisible message loss, worst under bulk fan-out.
- **Effort:** M (part of the notification-pipeline project)
- **Dependencies:** None; shares files with C3, H5, H6
- **Required validation:** Feature test: trip the limiter, assert the delivery is retried (not consumed) and eventually sends; assert quota is not burned on release.
- **Success criteria:** A rate-limited delivery is deferred and delivered, never dead-lettered for rate-limiting alone.

### C3 — Notification dedup is decorative; retries double-send
- **Source:** Audit §Critical C3; §Notifications
- **Severity:** Critical (release blocker)
- **Business impact:** Duplicate notifications erode trust and can double-trigger downstream actions.
- **Technical impact:** No unique index on `dedup_key`; `firstOrCreate` keyed on a just-created `notification_id`; default key minute-bucketed.
- **User impact:** Users receive the same notification twice on any retry.
- **Risk if ignored:** Guaranteed duplicates on every job retry.
- **Effort:** M
- **Dependencies:** DB migration (unique index); shares files with C2
- **Required validation:** Feature test: dispatch the same domain event twice, assert exactly one notification + one send. Migration test for the unique constraint.
- **Success criteria:** Idempotent dispatch; a real deterministic dedup key enforced by a unique index.

### C4 — Export job hangs forever in `processing`
- **Source:** Audit §Critical C4; §Automation
- **Severity:** Critical (High for release — admin convenience, not core path)
- **Business impact:** Admins see exports that never complete and never fail.
- **Technical impact:** Wrong queue (60s vs 300s), no `$timeout`/`$tries`/`failed()`; worker killed before `catch`.
- **User impact:** Admin-only; stuck export UI.
- **Risk if ignored:** Every export over 60s silently hangs.
- **Effort:** S (fix already written)
- **Dependencies:** None
- **Required validation:** Gates green; feature test asserting a failed export lands in `failed` state, not `processing`.
- **Success criteria:** Export routed to `exports` queue; timeout/tries/backoff/failed() present; no permanent `processing` rows.

### C5 — `retention()` loads unbounded tables into PHP
- **Source:** Audit §Critical C5; §Analytics; §Performance
- **Severity:** Critical (scale)
- **Business impact:** The retention report OOMs and 500s at real data volume.
- **Technical impact:** Whole `enrollments` + `lesson_progress` materialized into PHP, filtered after loading.
- **User impact:** Admin report unavailable at scale.
- **Risk if ignored:** Memory exhaustion, cascading worker failure.
- **Effort:** M
- **Dependencies:** H1 indexes make the rewrite measurable
- **Required validation:** Feature test asserting correct cohort output; query-count/plan check; manual test at seeded volume.
- **Success criteria:** One grouped SQL query with the window in the `WHERE`; bounded memory.

---

## HIGH

### H1 — Systemic missing FK/filter indexes
- **Source:** Audit §High H1; §Database
- **Severity:** High
- **Business/Technical impact:** Every `where('course_id')`/`whereBetween('paid_at')` sequential-scans the hottest tables.
- **User impact:** Slow reports and instructor pages under load.
- **Risk if ignored:** Linear degradation; timeouts at scale.
- **Effort:** S (migration) + review of query plans
- **Dependencies:** None; unblocks H2/H3/C5/H8 measurement
- **Required validation:** Migration runs on a copy; `EXPLAIN` on the named queries shows index use; gates green.
- **Success criteria:** Indexes on `enrollments.course_id`, `orders.paid_at`, `order_items.{order_id,product_id}`, `product_courses.course_id`, `course_trainer.user_id`, `certificates.issued_at`, `lesson_progress.completed_at`, `courses.published_at` composite.

### H2 — Per-lesson N+1 in the learner course player
- **Source:** Audit §High H2; §Performance
- **Severity:** High
- **Business/Technical impact:** ~240–360 queries per player load on a 120-lesson course.
- **User impact:** Slow learner experience — the core product surface.
- **Risk if ignored:** The most-used page degrades worst.
- **Effort:** M
- **Dependencies:** None (reuse enrollment/progress already loaded in the controller)
- **Required validation:** Query-count regression test; unchanged access-control behavior.
- **Success criteria:** Constant query count independent of lesson count; access rules unchanged.

### H3 — Instructor course list loops `courseStats()` unpaginated
- **Source:** Audit §High H3; §Performance
- **Severity:** High
- **Business/Technical impact:** 3+ queries/course over unbounded `->get()`.
- **User impact:** Slow instructor course list.
- **Risk if ignored:** Times out for prolific instructors.
- **Effort:** S–M (swap onto existing `CoursePerformanceService`)
- **Dependencies:** None
- **Required validation:** Query-count regression; identical response shape.
- **Success criteria:** Endpoint uses the batched service; paginated; bounded queries.

### H4 — Synchronous unbounded notification fan-out on announcement
- **Source:** Audit §High H4; §Notifications; §Automation
- **Severity:** High
- **Business/Technical impact:** ~25k queries in one request for a 5–10k-student course; times out mid-fan-out.
- **User impact:** Partially notified cohort; instructor sees a failed request.
- **Risk if ignored:** Announcements unusable for large courses.
- **Effort:** M
- **Dependencies:** Phase-2 queued-listener pattern
- **Required validation:** Feature test: large cohort fan-out is queued + chunked, resumable.
- **Success criteria:** Fan-out queued, chunked, idempotent; request returns immediately.

### H5 — Dead-letter / delivery events have no listeners
- **Source:** Audit §High H5; §Observability
- **Severity:** High
- **Business/Technical impact:** Deliveries go `Dead` with no alert; evidence pruned after 7 days.
- **User impact:** Failures invisible to operators.
- **Risk if ignored:** Silent, unrecoverable message loss.
- **Effort:** S–M
- **Dependencies:** Notification fixes (C2/C3) landing first so the signal is meaningful
- **Required validation:** Test that a dead-letter event triggers the alert path; Filament widget renders counts.
- **Success criteria:** Dead-letter and failed-job growth are alerted and surfaced in the admin panel.

### H6 — Channels report false delivery
- **Source:** Audit §High H6; §Notifications
- **Severity:** High (conditional release blocker)
- **Business/Technical impact:** `WhatsAppChannel` no-op marks `Sent`; email/SMS/push never selected; ledger lies.
- **User impact:** Recorded-as-sent messages never arrive.
- **Risk if ignored:** Corrupted delivery ledger; false confidence.
- **Effort:** M
- **Dependencies:** Product decision on which channels ship in v1
- **Required validation:** Test: an unwired channel marks `Failed`/`Skipped`, never `Sent`; `default_channels` behavior asserted.
- **Success criteria:** Ledger reflects reality; v1 ships in-app-only OR wired channels only.

### H7 — Sanctum tokens never expire
- **Source:** Audit §High H7; §Security; §Authentication
- **Severity:** High
- **Business/Technical impact:** Stolen bearer token valid indefinitely.
- **User impact:** None visible; standing account-takeover risk.
- **Risk if ignored:** Long-lived credential exposure.
- **Effort:** S
- **Dependencies:** None
- **Required validation:** Config test; scheduled `sanctum:prune-expired`; expiry honored in an auth test.
- **Success criteria:** Finite token lifetime + pruning scheduled.

### H8 — No caching on 12 analytics insight endpoints; PHP pagination
- **Source:** Audit §High H8; §Analytics; §Caching
- **Severity:** High
- **Business/Technical impact:** Most expensive queries re-run every dashboard load; `per_page` buys nothing.
- **User impact:** Slow admin analytics.
- **Risk if ignored:** DB load spikes on dashboard refresh.
- **Effort:** M–L
- **Dependencies:** H1 indexes for the SQL-pagination rewrite
- **Required validation:** Cache-hit test; SQL pagination correctness; `total` still accurate.
- **Success criteria:** Insight endpoints cached with discriminated keys; pagination/sort in SQL.

### H9 — Live-session reminders written but never delivered
- **Source:** Audit §High H9; §Automation
- **Severity:** High
- **Business/Technical impact:** Every reminder sits `Pending` forever.
- **User impact:** Learners never receive session reminders.
- **Risk if ignored:** A modeled feature that silently does nothing.
- **Effort:** M
- **Dependencies:** Phase-2 scheduler/queue work
- **Required validation:** Scheduled command test: pending reminders due now are dispatched exactly once.
- **Success criteria:** A scheduled consumer delivers due reminders idempotently.

---

## MEDIUM

### M1 — Signed file URLs lack ownership check
- **Source:** Audit §Medium M1; §Security. **Severity:** Medium. **Impact:** URLs are bearer-equivalent, leak via logs/history. **Effort:** S. **Deps:** none. **Validation:** owner-mismatch returns 403 even with a valid signature. **Success:** owner check inside export + certificate file handlers.

### M2 — No correlation-id propagation into queued work
- **Source:** M2; §Logging. **Severity:** Medium. **Impact:** `correlation_id` absent from every job log line. **Effort:** S. **Deps:** none. **Validation:** a queued job log line carries the originating request's correlation id. **Success:** id propagated via job payload/`createPayloadUsing`.

### M3 — Zero logging in notification delivery path
- **Source:** M3; §Logging. **Severity:** Medium. **Impact:** async failures are the least logged. **Effort:** S. **Deps:** none. **Validation:** send/retry/dead-letter each emit a structured log line. **Success:** delivery path observable in logs.

### M4 — Every listener synchronous
- **Source:** M4; §Automation. **Severity:** Medium. **Impact:** registration/analytics/OTP run in-request; `SessionScheduled` N+1 in-request. **Effort:** M. **Deps:** none. **Validation:** listeners implement `ShouldQueue` + `ShouldHandleEventsAfterCommit`; request latency drops. **Success:** heavy listeners queued.

### M5 — `whereDate()` defeats indexes
- **Source:** M5; §Performance. **Severity:** Medium. **Impact:** non-sargable filters stay sequential scans even after H1. **Effort:** S. **Deps:** H1. **Validation:** `EXPLAIN` shows index use post-change. **Success:** half-open range comparisons.

### M6 — Duplicated aggregate logic
- **Source:** M6; §Reporting. **Severity:** Medium. **Impact:** enrollment aggregate ×3, rate arithmetic ×6 drift independently. **Effort:** M. **Deps:** none. **Validation:** callers route through `EnrollmentStatsPort`/`EnrollmentStats::completionRate()`/shared rate helper; gates green. **Success:** single source per calculation.

### M7 — Unpaginated collection endpoints
- **Source:** M7; §API. **Severity:** Medium. **Impact:** ~12 endpoints return unbounded sets incl. public `/seo/sitemap`. **Effort:** M. **Deps:** none. **Validation:** each returns a paginated envelope; contract tests updated. **Success:** all list endpoints paginated or provably bounded.

### M8 — Export streamed through memory
- **Source:** M8; §Performance. **Severity:** Medium. **Impact:** large XLSX exhausts `memory_limit`. **Effort:** S. **Deps:** none. **Validation:** large export downloads without loading whole file into a string. **Success:** `download()`/`StreamedResponse`.

### M9 — Rate-limit keying/coverage gaps
- **Source:** M9; §Security. **Severity:** Medium. **Impact:** register/password IP-only; public surface incl. webhook unthrottled. **Effort:** S. **Deps:** none. **Validation:** throttle tests on webhook + public GETs; keying includes an identity dimension. **Success:** public surface throttled; webhook throttled.

### M10 — `->can()` under Sanctum silently denies
- **Source:** M10; §Authorization. **Severity:** Medium (functionality bug). **Impact:** live-session mgmt + certificate revoke/reissue unreachable for non-super-admins. **Effort:** S (<0.5 day). **Deps:** none. **Validation:** a permitted non-super-admin can perform both actions. **Success:** policies use `hasPermission()`.

### M11 — `VERSION` disagrees with release docs
- **Source:** M11; §Documentation. **Severity:** Medium. **Impact:** build tooling reads a wrong version. **Effort:** S. **Deps:** none. **Validation:** `VERSION`/`README`/`CHANGELOG` agree. **Success:** single reconciled version string.

---

## LOW (audit L1–L11 — batch as cleanup; none block release)

| ID | Title | Effort | Validation | Success |
|---|---|---|---|---|
| L1 | `APP_DEBUG=true` in `.env.example` | S | example flipped to false | template hygiene |
| L2 | 3 models `$guarded = []` | S | tighten to `$fillable` | gates green |
| L3 | `provider`/`Failed` state unused | S | populate provider; use `Failed` | ledger completeness |
| L4 | Template lookups uncached / double-rendered | S | cache lookup; render once | fewer hot-path queries |
| L5 | Over-eager catalog list loading | S | trim `with()` to used relations | fewer queries |
| L6 | 5/6 scheduled tasks lack overlap guard | S | add `withoutOverlapping` | no double-write |
| L7 | Plaintext OTP logged (local) | S | remove the log line | no credential in logs |
| L8 | Dead exceptions/traits/Actions | M | delete + re-run PHPStan/Deptrac per batch | no dead symbols |
| L9 | ~40 orphan i18n keys; no global parity test | S | add parity test; drop orphans | zero drift locked in |
| L10 | `mfa/disable`/`verify-phone` half-features | M | ship or remove + document | no unreachable routes |
| L11 | Stale `about`/`contact` visual baselines | S | regenerate after human review | visual gate green |

---

## NOT VERIFIED (audit could not prove from the repository — do not schedule as work until confirmed)

- **NV1 — Infrastructure runtime behavior.** Audit §Infrastructure: container/cluster behavior not assessable statically. The **CLI `php.ini` memory_limit not loading** is proven (Pest OOM at 128M); the rest of infra is Not Verified.
- **NV2 — API reference documentation presence.** Audit §Documentation: could not confirm an OpenAPI/collection exists or is absent.
- **NV3 — Audit-log coverage.** Audit §Observability: `AuditLog` model exists; which actions are audited was not examined.
- **NV4 — Whether the 14 unreferenced feature flags gate anything via seeded DB nav rows.** Audit §Dead Code: flags are admin-bindable at runtime; can't be proven dead statically.
- **NV5 — Whether the 6 "possibly dead" enums are compared as raw DB strings.** Audit §Dead Code medium-confidence.

These are investigation tasks, not implementation tasks. Assign as spikes before acting.

---

# Execution Phases

Derived from the audit's roadmap, not the template. Each phase names its exit gate.

**Phase 0 — Validation (0.5 day).** Run backend + frontend gates against the prior-execution fixes (C1, C4). *Exit:* all gates green, or the failed fix returns to Phase 1. Nothing below is trustworthy until this passes.

**Phase 1 — Release Blockers (4–6 days).** C1 confirm/repair + secret rotation + order audit; C2 + C3 + H6 (notification correctness); H7 + M9 (webhook throttle) + M10 as quick-win security riders. *Exit:* all three blockers closed and validated; gates green.

**Phase 2 — Async Resilience & Observability (3–4 days).** C4 validate; M4 (queue listeners); H4 (queued fan-out); H9 (reminder consumer); H5 (dead-letter alerting); M2 + M3 (correlation id + delivery logging). *Exit:* no synchronous heavy listeners; dead-letter and failed-job growth alerted.

**Phase 3 — Database & Performance (3–4 days, parallel to Phase 2).** H1 (index migration, first); H2 (player batching); H3 (instructor list); M5 (`whereDate`→range); M8 (export streaming). *Exit:* query-count regressions pass; named queries use indexes.

**Phase 4 — Analytics & Caching (3–4 days, after Phase 3).** C5 (retention rewrite); H8 (SQL pagination + caching); M6 (aggregate centralization); Caching discriminators + invalidation. *Exit:* insight endpoints cached and bounded.

**Phase 5 — Automation-Engine Decision (0.5 day to 2 weeks — product gate).** Build or formally defer `WorkflowEngine`/`DigestService`/`ScheduledAutomation`. *Exit:* shipped OR documented in `KNOWN_LIMITATIONS.md`. **Must not block the RC.**

**Phase 6 — Cleanup, Docs, CI (2–3 days).** L1–L11 as capacity allows; M11 version reconciliation; e2e auth fixture + dashboard in CI; NV1 CLI memory fix; visual-baseline regeneration; NV2–NV5 spikes. *Exit:* CI exercises the authenticated dashboard; version strings agree.

**Phase 7 — Release Candidate (1 day).** Run the full Quality Gates and Release Checklist below. *Exit:* Definition of Done met.

---

# Parallel Work

Two engineers (one backend-leaning, one full-stack) plus part-time DevOps and QA can run Phases 1–4 with meaningful overlap. The hard serialization is only C1→everything (Phase 0) and H1→H2/H3/C5/H8 (indexes before the query work is worth measuring).

| Track | Backend | Frontend | DevOps | QA |
|---|---|---|---|---|
| **Phase 0** | — | — | Run gates in CI | Confirm green; sign off on C1/C4 |
| **Phase 1 (blockers)** | C1 repair, C2, C3, H6, H7, M9, M10 | — (delivery-status UI optional) | Rotate `COMMERCE_WEBHOOK_SECRET`; prod order audit query | Webhook + notification + throttle feature tests |
| **Phase 2 (async)** | C4, M4, H4, H9, H5, M2, M3 | Dead-letter Filament widget (H5) | Horizon `exports` supervisor verify; scheduler cron for H9 | Queue idempotency + reminder tests |
| **Phase 3 (perf)** | H1 migration, H2, H3, M5, M8 | — | Run migration on staging copy; capture `EXPLAIN` | Query-count regressions |
| **Phase 4 (analytics)** | C5, H8, M6, caching | Verify dashboard still renders under cached data | Cache backend (Redis) capacity check | Cache-hit + pagination correctness |
| **Phase 6 (cleanup)** | L2, L3, L4, L8, M6 tail | L1, L5, L9, L11, dead teach-dashboard chain | NV1 CLI memory fix; pin Deptrac/Rector | e2e auth fixture; baseline regen |

**Genuinely parallel from day one:** Phase 3 (perf) has no dependency on Phase 1/2 and can start immediately with the second engineer, *except* it should not be validated for speed until H1 lands within its own phase.

---

# Critical Path

The true critical path is the money-and-messaging spine, because those are the only release blockers and they share files:

```
Phase 0 (gates green)
  → C1 confirmed (0.5d)
  → C2 + C3 + H6 notification correctness (3–4d, shared files, must be sequential within the pipeline)
  → H5 dead-letter alerting (depends on C2/C3 signal being meaningful) (1d)
  → Phase 7 RC gate
```

**Critical path duration: ~6–7 working days.** Everything else — all of Phase 3 (performance), H8/C5 analytics, cleanup, docs — is **off** the critical path and should be postponed or parallelized behind the second engineer. The automation-engine build (Phase 5), if chosen, is explicitly kept off the critical path by the defer-and-document option.

**Postpone (off critical path):** H2, H3, M5, M8 (perf — parallel track), C5, H8, M6 (analytics — after perf), all of L1–L11, M11, NV spikes, Phase 6.

---

# Business Risk Matrix (Critical + High)

Probability × Impact → Priority (P0 = do first). Owner is a role, not a name — assign on team formation.

| ID | Risk | Probability | Impact | Priority | Mitigation | Owner |
|---|---|---|---|---|---|---|
| C1 | Free-course fraud via unsigned webhook | High (default config) | Critical (revenue) | **P0** | Validate fix, rotate secret, audit orders, throttle webhook | Backend lead |
| C2 | Transactional messages silently lost | High | Critical (trust) | **P0** | Atomic claim, don't burn attempts on rate-limit | Backend |
| C3 | Duplicate notifications on retry | High (every retry) | High | **P0** | Real dedup key + unique index | Backend |
| H6 | Ledger records false delivery | Medium (if channels enabled) | High | **P1** | Gate unwired channels; mark `Failed` not `Sent` | Backend + Product |
| C4 | Exports hang forever | Medium | High (admin) | **P1** | Validate queue/timeout/failed() fix | Backend |
| C5 | Retention report OOMs | Medium (at scale) | High | **P1** | SQL rewrite | Backend |
| H1 | Hot-table sequential scans | High (at scale) | High | **P1** | Index migration | Backend + DevOps |
| H2 | Learner player N+1 | High | High (core UX) | **P1** | Hoist + batch | Backend |
| H7 | Indefinite token lifetime | Low-Med | High (ATO) | **P1** | Set expiry + prune | Backend |
| H4 | Announcement fan-out times out | Medium | High | **P2** | Queue + chunk | Backend |
| H5 | Silent async failures | High | High (ops blind) | **P2** | Alert on dead-letter/failed | Backend + DevOps |
| H3 | Instructor list slow/timeouts | Medium | Medium-High | **P2** | Swap onto batched service | Backend |
| H8 | Analytics DB load spikes | Medium | Medium-High | **P2** | Cache + SQL pagination | Backend |
| H9 | Session reminders never sent | High (feature dead) | Medium | **P2** | Scheduled consumer | Backend |

---

# Release Checklist

Every item traces to an audit area. **[✓]** = audit found it already in place; **[ ]** = work required; **[NV]** = Not Verified, needs a spike.

**Backend**
- [ ] Backend gates green post-fixes (Pint, PHPStan L6, Deptrac, Pest) — Phase 0
- [✓] Standard JSON envelope + `AuthenticationException` override (§API)
- [ ] Unpaginated list endpoints paginated (M7)

**Frontend**
- [✓] ESLint/tsc/Vitest/build/Storybook green (Step 5, host-verified)
- [ ] Dead teach-dashboard chain removed (§Dead Code)
- [ ] Authenticated dashboard exercised in CI (§CI/CD, §Frontend)

**Infrastructure**
- [✓] Dockerfiles build + Trivy-scanned in CI (§CI/CD)
- [ ] CLI `php.ini` loads tuned memory_limit (NV1)
- [NV] Cluster runtime behavior (NV1)

**Security**
- [ ] C1 validated; secret rotated (C1)
- [ ] Token expiry set (H7)
- [ ] Signed-URL owner checks (M1)
- [✓] IDOR-clean, no mass assignment, no SQL injection, CSP/CORS/headers (§Security)

**Queues / Redis / Horizon**
- [✓] Horizon 3-supervisor config, `after_commit=true`, `failed_jobs` migrated (§Automation)
- [ ] Export routed to `exports` queue (C4)
- [ ] Heavy listeners queued (M4)
- [ ] Job idempotency / atomic delivery claim (C2/C3)

**Scheduler**
- [✓] `horizon:snapshot` + prunes scheduled (§Automation)
- [ ] Reminder-dispatch command scheduled (H9)
- [ ] Overlap guards on scheduled tasks (L6)

**Storage**
- [ ] Export streamed not memory-loaded (M8)

**Database**
- [ ] FK/date index migration applied (H1)
- [ ] `whereDate`→range (M5)

**Backups / Disaster Recovery**
- [NV] Backup + restore runbook validated (§Infrastructure — runbooks exist, execution Not Verified)

**Monitoring / Logging / Sentry**
- [✓] Structured JSON logging, Sentry wired, health probes (§Observability)
- [ ] Correlation id in queued work (M2)
- [ ] Delivery-path logging (M3)
- [ ] Dead-letter / failed-job alerting (H5)

**Email / Notifications**
- [ ] Notification correctness (C2/C3) validated
- [ ] Channel truthfulness (H6) — v1 channel scope decided
- [✓] Localization + template fallback (§Notifications)

**Payments**
- [ ] Webhook signature fail-closed validated (C1)
- [ ] Webhook throttled (M9)
- [ ] Production `COMMERCE_PAYMENT_PROVIDER=stripe` + guard (C1)

**Feature Flags**
- [NV] Confirm the 14 unreferenced flags are intentional (NV4)

**Environment Variables / Secrets / HTTPS**
- [ ] `COMMERCE_WEBHOOK_SECRET` rotated (C1)
- [ ] `.env.example` `APP_DEBUG=false` (L1)
- [ ] `TRUSTED_PROXIES` pinned in production (§Infrastructure)
- [✓] gitleaks secret scan in CI (§CI/CD)

**Rate Limiting**
- [ ] Public surface + webhook throttled; register/password keyed with identity (M9)

**Health Checks**
- [✓] `/health/live`, `/health/ready` (Postgres+Redis, 503-on-down), `/up` (§Observability)

**Rollback Plan**
- [NV] Rollback runbook validated (§Infrastructure — `docs/ops/` exists, execution Not Verified)

**Documentation**
- [ ] `VERSION`/`README`/`CHANGELOG` reconciled (M11)
- [ ] Automation engine shipped or deferred-documented (Phase 5)

**Support Readiness**
- [NV] Support runbook / known-issues list current (§Documentation — Not Verified)

---

# Quality Gates (must pass before RC)

**Backend** — Pint (0 issues), PHPStan L6 + architecture rules (0 errors), Deptrac (0 new violations, baseline unchanged), Pest (100% pass) — all on the host with tuned memory.
**Frontend** — ESLint (0 errors), `tsc --noEmit` (clean), Vitest (100% pass), `next build` (success), Storybook build (success).
**Infrastructure** — Docker images build + Trivy CRITICAL/HIGH clean (existing `.trivyignore` exception only); CLI memory_limit fix confirmed by a green host Pest run without `-d memory_limit`.
**Security** — C1 validated; gitleaks clean; `composer audit` + `npm audit --omit=dev` clean (already CI-blocking); token expiry + webhook throttle in place.
**Performance** — query-count regression tests pass for the learner player (H2), instructor list (H3), and course performance (existing); retention bounded (C5).
**Accessibility** — Playwright + axe on public surfaces (existing) **plus** the authenticated instructor dashboard once the auth fixture lands (§CI/CD).
**Browser Tests** — Playwright smoke + a11y green in CI including the authenticated journey; visual-regression reviewed (advisory, baselines regenerated).
**Load Tests** — **Not currently in the repo (Not Verified).** *Assumption:* a basic load test against the learner player and analytics endpoints post-index is advisable but the audit does not evidence an existing harness; treat as an optional pre-RC spike, not a hard gate, unless the team adds one.

---

# Definition of Done — "Production Ready v1.0" (objective)

All of the following are true, each independently checkable:

1. All three release blockers (C1, C2+C3, H6) are **closed and validated by a passing test**, not by inspection.
2. Backend gates (Pint, PHPStan, Deptrac, Pest) and frontend gates (ESLint, tsc, Vitest, build, Storybook) are **green on the host**, with Pest passing at the container's default memory limit (no `-d memory_limit` override needed).
3. The authenticated instructor dashboard passes Playwright smoke + axe **in CI**.
4. The FK/date index migration (H1) is applied and the three query-count regression tests (H2, H3, existing performance) pass.
5. `retention()` (C5) returns correct output at seeded volume without OOM.
6. Payment webhook rejects unsigned/wrong/replayed requests (C1), is throttled (M9), and production is configured `COMMERCE_PAYMENT_PROVIDER=stripe` with the resolve-time guard active.
7. Sanctum tokens expire (H7); public surface and webhook are throttled (M9).
8. Dead-letter and failed-job growth are alerted and visible in Filament (H5); notification delivery emits structured logs with a propagated correlation id (M2, M3).
9. The automation engine is either shipped-and-tested or listed in `KNOWN_LIMITATIONS.md` as deferred (Phase 5).
10. `VERSION`, `README`, and `CHANGELOG` state one identical version (M11).
11. Every item on the Release Checklist is either **[✓]** or explicitly waived in writing with an owner and a follow-up ticket. No **[ ]** remains unaddressed; no **[NV]** remains uninvestigated.

Anything not in this list is a Nice-to-Have and may ship as a documented known-limitation.

---

# Recommended Execution Order (and why it minimizes risk)

1. **Phase 0 first, always.** Three fixes are unvalidated. Building on an unproven baseline risks compounding a defect the way the Step-5 blind edits did. Cost: half a day. Payoff: everything after it is trustworthy.

2. **Release blockers before anything else (Phase 1).** They are the only things that legally/financially prevent shipping. C1 is money leaving the business *today* on the default config; C2/C3 corrupt the transactional messaging users rely on. They also share files, so doing them together avoids merge churn. Security quick-wins (H7, M9, M10) ride along because they touch adjacent code and cost hours.

3. **Async resilience next (Phase 2), because it depends on the notification fixes.** H5's dead-letter alerting is only meaningful once C2/C3 stop generating false dead-letters. Queuing the listeners (M4) and fan-out (H4) removes the request-timeout class of failure that would otherwise mask other bugs.

4. **Performance in parallel (Phase 3), gated internally on H1.** It has no dependency on the blocker work, so a second engineer runs it from day one — but the index migration must land before H2/H3/C5/H8 speed claims can be measured, so H1 is first within its own phase. This keeps the critical path short (blockers only) while making full use of a second pair of hands.

5. **Analytics after performance (Phase 4).** The query rewrites (C5, H8) are only worth benchmarking once the indexes exist. Caching (H8) is added on top of the corrected queries, not before — caching a slow query hides the problem instead of fixing it.

6. **Automation-engine decision late and off the critical path (Phase 5).** It is the single largest scope-uncertainty in the plan (0.5 day to 2 weeks). Forcing the decision late lets the team see how much runway remains, and the defer-and-document option guarantees it never blocks the RC.

7. **Cleanup, docs, CI last (Phase 6).** Dead-code deletion is safest after the real changes settle (fewer merge conflicts, and PHPStan/Deptrac re-run per batch catches anything the deletions break). The e2e auth fixture lands here so the RC gate can finally exercise the dashboard in a browser — the one verification Step 5 could never provide.

The ordering is deliberately **risk-first, not value-first**: it closes the ways the product can lose money or lie to users before it optimizes anything, and it front-loads the only truly serial work (blockers) so the rest can fan out across the team.

---

*Traceability note: every backlog ID (C1–C5, H1–H9, M1–M11, L1–L11) maps 1:1 to a finding in `STEP_6_TO_9_ENGINEERING_AUDIT.md`. NV1–NV5 are the audit's explicitly-unproven items, carried here as spikes rather than scheduled work. No engineering work appears in this plan that is not present in the audit.*
