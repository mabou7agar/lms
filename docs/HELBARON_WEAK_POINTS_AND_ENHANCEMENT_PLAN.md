# HElbaron LMS — Weak Points and Enhancement Plan

**Audit date:** 2026-08-30  
**Repository state reviewed:** `bc8216d4c66dddab732a7f50cefcceeb71472f23`  
**Scope:** HElbaron only. This is not a comparison with another LMS.

## Purpose

This document gives engineering and product teams one prioritized backlog of the
known weaknesses, incomplete production capabilities, operational risks, and
recommended additions in HElbaron LMS.

The findings were consolidated from:

- Current source code on the Stage 4 pull-request branch.
- The focused learning/media/assessment code graph.
- Repository QA, release, production, and known-limitations documents.
- Production smoke testing of the deployed Dokploy environment.
- Focused backend test results.

Previously repaired production defects are intentionally excluded. These include
the broken Filament styling, the missing backend courses route, course/product
slug defects, invalid email-verification navigation, enrollment labels, the
empty-lesson publishing guard, unsafe AI fake-provider fallback, favicon issues,
and the free-enrollment journey.

## Completeness and confidence

This is the most comprehensive evidence-based backlog currently available, but
no static audit can guarantee that every possible defect is listed.

The following classifications are used:

- **Confirmed gap:** demonstrated by current code, configuration, or production
  behavior.
- **Implemented but unverified:** implementation exists, but the complete journey
  has not been proven with real production-like credentials and data.
- **Recommended enhancement:** not necessarily a defect; its priority depends on
  customer and enterprise requirements.

Confidence is highest for learning, media, assignments, assessments, grading,
authentication, and the production paths that were exercised. Confidence is
lower for real payment, notification, SSO, AI, video-provider, backup/restore,
and high-load behavior because those require credentials, representative data,
or operator-level tests that were not available during the audit.

## Executive assessment

HElbaron has a substantial LMS implementation and a strong modular foundation,
but it should not yet be represented as fully production-proven or enterprise-ready. The principal issue is not the absence of basic LMS screens. It is the
gap between implemented code and complete production evidence across external
providers, critical journeys, operations, scale, and enterprise isolation.

The release decision should be based on the P0 exit criteria below, not on the
number of implemented modules.

## P0 — Production launch blockers

### P0.1 Real video delivery is not operational end to end

**Classification:** Confirmed gap.

Production was configured with S3 ingestion while learner playback remained on
the fake provider. The ingestion logic can also route streamed video/audio to Mux
when the ingestion provider is not local/fake, without a fully proven Mux setup.

**Required work**

- Configure Mux token ID, token secret, webhook secret, signing-key ID, and
  signing private key through the production secret store.
- Select the real playback provider in production.
- Verify Mux webhook signature handling and asset lifecycle transitions.
- Add playback-token refresh for lessons longer than the signed-token lifetime.
- Provide upload retry/resume, processing, failure, replacement, and deletion UX.
- Test upload -> processing -> secure playback -> progress -> completion.

**Acceptance criteria**

- An authorized learner can watch a real uploaded video and resume progress.
- Unauthorized and unenrolled users cannot retrieve a playable URL.
- Long lessons continue playing after token refresh.
- Invalid webhooks are rejected and duplicate webhooks are idempotent.
- Processing and provider failures produce actionable UI and operator alerts.

### P0.2 Real external integrations are not production-proven

**Classification:** Implemented but unverified.

Code exists for several providers, but code presence is not evidence that live
credentials, callbacks, failure paths, quotas, and reconciliation work together.

**Required journeys**

- Payment authorization, success, failure, retry, webhook, refund, and
  entitlement reconciliation.
- Transactional email, SMS, and push delivery with real providers.
- OIDC SSO login, account linking, logout, and rejected/expired assertions.
- AI generation, embeddings, retrieval, quota enforcement, and provider failure.
- S3/CloudFront/Mux upload, webhook, signed delivery, and expiration.

**Acceptance criteria**

- Every required provider passes an automated sandbox journey.
- Production contains no fake provider for an advertised capability.
- Provider timeouts, duplicate callbacks, and partial failures are recoverable.
- Secrets are stored outside source control and have documented rotation owners.

### P0.3 The complete automated quality gate is not proven

**Classification:** Confirmed gap.

Focused backend verification passed 59 tests with 126 assertions, but the full
Laravel suite did not complete locally because PostgreSQL rejected the temporary
test database credentials.

**Required work**

- Run the complete backend suite against the production database engine.
- Run migrations from an empty database and upgrade from the last release.
- Add/complete frontend unit, integration, accessibility, and browser E2E tests.
- Exercise queues, schedules, webhooks, provider retries, and idempotency.
- Require backend, frontend, architecture, and E2E checks in branch protection.

**Acceptance criteria**

- A clean CI run is green from a new database.
- Required checks cannot be bypassed when merging to the release branch.
- Test and architecture dependencies are locked and reproducible.

### P0.4 Critical journeys remain unverified

**Classification:** Confirmed evidence gap.

The following must be exercised on production-like staging:

- Registration, email verification, login, remember-me, password reset, MFA,
  session/device revocation, and logout.
- Free and paid enrollment, coupon, failed payment, refund, and entitlement loss.
- Instructor course creation, lesson creation, readiness validation, and publish.
- Real video playback, resume, completion, captions, and access expiry.
- Quiz attempt, autosave, timeout, submission, scoring, feedback, and retake.
- Assignment upload, resubmission, rubric grading, change request, release, and
  gradebook export.
- Certificate generation, download, verification, and revocation.
- Live-session scheduling, join, attendance, recording, and reminder delivery.
- Notification preferences and real provider delivery.
- OIDC SSO and AI-assisted journeys.

**Acceptance criteria**

- Each journey has a repeatable test case with screenshots/log evidence.
- Failure and recovery cases are covered, not only happy paths.
- Authorization is tested using different organizations, roles, and users.

### P0.5 Operational recovery and observability are not demonstrated

**Classification:** Confirmed evidence gap.

**Required work**

- Perform a database and object-storage backup restoration drill.
- Test deployment rollback, migration rollback policy, and queue recovery.
- Monitor API availability, Horizon/queues, failed jobs, scheduler execution,
  payments, webhooks, media processing, email delivery, and certificate jobs.
- Add error budgets, alert thresholds, ownership, and escalation documentation.
- Add dependency, container-image, and secret scanning.
- Maintain separate staging and production environments.
- Rotate any credentials that have been disclosed outside the secret manager.

**Acceptance criteria**

- Recovery time and recovery point objectives are defined and demonstrated.
- A failed deployment can be rolled back without data loss.
- Alerts are tested and route to a named operator.

### P0.6 Launch content is not ready

**Classification:** Confirmed gap.

The available course was correctly prevented from publishing because its
published lesson had no usable content. That guard is working as intended, but a
launch still needs realistic content.

**Required work**

- Prepare at least one complete Arabic/English course.
- Include real lessons, media, captions, a quiz, an assignment, a product,
  enrollment, completion rules, and a certificate.
- Replace placeholder preview videos and fabricated marketing statistics.
- Validate RTL, mobile, accessibility, and SEO for the launch content.

## P1 — Product and engineering weaknesses

### P1.1 Assessment types are too limited for advanced exams

**Classification:** Confirmed product gap.

Implemented auto-graded types include single choice, multiple choice,
true/false, short answer, and fill-in-the-blank. The architecture anticipates
additional types, but no currently implemented question type exercises a manual
assessment-review path.

**Enhancements**

- Essay/manual-review questions.
- Numeric, matching, ordering, hotspot, coding, and drag-and-drop questions.
- Question pools, randomization, and balanced exam assembly.
- Question bank import/export and versioning.
- Per-learner time and attempt accommodations.
- Moderation, regrading, score adjustment, and appeal workflows.
- Question difficulty, discrimination, distractor, and outcome analytics.
- Optional plagiarism detection, identity checks, or proctoring where required.

**Acceptance criteria**

- Manual questions remain pending until reviewed and cannot be auto-completed.
- Regrading creates an audit event and consistently updates the gradebook.
- Attempt rules are enforced server-side and covered by concurrency tests.

### P1.2 Remember-me behavior is misleading

**Classification:** Confirmed defect.

The learner-facing checkbox does not select a different session policy. The web
BFF cookie uses a fixed persistent duration while the Laravel admin session has a
different duration.

**Required work**

- Unchecked login must create a session-only cookie.
- Checked login must use a configurable persistent duration.
- Centralize session and token lifetimes in configuration.
- Test refresh, expiry, revocation, password change, and logout.

### P1.3 Automation and digest execution are incomplete

**Classification:** Confirmed gap.

Automation-related models and services exist, but the known-limitations audit
records no route, command, job, or scheduler entry that invokes the workflow and
digest engines.

**Required work**

- Either hide the capability until it is implemented or add trigger, scheduler,
  execution, retry, idempotency, audit, and operator controls.
- Prevent duplicate automation delivery under concurrent workers.


### P1.4 Enterprise tenancy is incomplete

**Classification:** Confirmed enterprise gap.

HElbaron currently behaves primarily as a single academy with organization/B2B
features. It should not be marketed as a fully isolated, self-service multi-tenant SaaS until all tenant-owned domains are consistently scoped and tenant
provisioning is operationally proven.

**Required work if multi-tenancy is a product requirement**

- Persist and provision tenants with lifecycle, domains, limits, and branding.
- Apply tenant isolation to all tenant-owned records and indirect relations.
- Add composite tenant indexes and cross-tenant leakage tests.
- Add tenant-aware queues, caches, files, search, analytics, webhooks, and exports.
- Provide tenant switch/provision/suspend/delete workflows.
- Add tenant-level usage, quota, billing, and audit controls.

### P1.5 Enterprise RBAC is too coarse

**Classification:** Recommended enhancement.

Add organization-scoped roles such as content editor, assessment reviewer,
finance/refund operator, support agent, reporting analyst, compliance auditor,
and organization manager.

**Acceptance criteria**

- Permissions are enforced on the backend, not only hidden in the UI.
- Privileged changes are audited.
- Cross-role and cross-organization authorization tests are mandatory.

### P1.6 Instructor workspace needs full capability validation

**Classification:** Implemented but unverified.

The instructor workspace already contains courses, students, media, sessions,
questions, earnings, and application routes. It should not be requested again as
a missing module.

**Required work**

- Validate every route, empty/error/loading state, and permission boundary.
- Ensure instructors cannot inspect or mutate another instructor's private data.
- Complete any placeholder screens and reconcile instructor/admin workflows.

### P1.7 Media is a high-coupling architectural hotspot

**Classification:** Confirmed architecture risk.

The code graph identifies `MediaAsset` as the largest hub, with 67 connections
across ingestion, playback, captions, assignments, assessments, administration,
visibility, attachments, and auditing. Shared-media communities also have low
cohesion.

**Required work**

- Keep Mux, S3, and CloudFront behind stable provider ports/adapters.
- Separate digital-asset management, ingestion, and learner playback concerns.
- Keep course, assessment, and authorization rules outside the media model.
- Add provider contract tests and lifecycle-state tests.
- Review this boundary before adding further behavior to `MediaAsset`.

### P1.8 Video-learning experience needs hardening

**Classification:** Recommended after P0.1.

**Enhancements**

- Caption and transcript authoring, review, and translation.
- Automatic transcript generation with human correction.
- Accessible player controls and keyboard/screen-reader validation.
- Playback recovery and cross-device resume.
- Engagement, drop-off, and playback-quality analytics.
- Storage/bandwidth quotas and cost alerts.
- Original-source backup and restoration.
- Optional watermarking or DRM for premium content.
- Bulk media import and migration tools.

### P1.9 Pluggable video-provider coverage is incomplete

**Classification:** Confirmed extensibility gap.

The current media platform supports fake/local, Mux, and S3 ingestion, with
fake, Mux, S3, and CloudFront playback. No Bunny Stream adapter is implemented.
Provider abstractions already exist, so additional vendors must be added as
adapters rather than by introducing vendor-specific rules into Learning,
Authoring, or `MediaAsset`.

**Recommended provider strategy**

- Keep Mux as a supported managed-video provider.
- Add Bunny Stream as the next managed-video adapter, subject to a cost,
  regional-delivery, security, analytics, and support evaluation.
- Retain S3 + CloudFront for private object delivery and customers that prefer
  infrastructure ownership.
- Evaluate Cloudflare Stream or another vendor only when a customer or regional
  requirement justifies the maintenance and test burden.
- Do not promise transparent live failover between video vendors; provide an
  explicit migration/re-ingestion workflow because provider asset identifiers,
  encodings, analytics, captions, and DRM licenses are not portable by default.

**Required work**

- Add a provider-capability DTO describing direct/resumable upload, HLS playback,
  signed/token authentication, webhook lifecycle, captions, transcripts,
  thumbnails, analytics, watermarking, DRM, deletion, and source retention.
- Implement `BunnyStreamIngestionProvider`, a Bunny playback-token adapter, and
  a Bunny webhook translator behind the existing media contracts.
- Add configuration and secret validation without using provider credentials
  outside configuration/adapters.
- Normalize Bunny video state, duration, captions, and processing failures into
  the existing provider-neutral media lifecycle.
- Add contract tests that every provider must pass, plus sandbox integration
  tests for upload, webhook idempotency, playback expiry, deletion, and failure.
- Add per-organization provider selection only after tenant isolation and
  provider-specific quota/cost accounting are complete.

**Acceptance criteria**

- The same learner and instructor APIs work without provider-specific payloads.
- Provider references and credentials are never exposed to clients.
- An operator can select a supported provider through configuration and pass the
  complete upload -> processing -> signed playback -> deletion journey.
- Provider capability differences are reported explicitly rather than silently
  degrading security or functionality.

**Bunny capabilities to evaluate**

Bunny's current APIs expose video lifecycle/status and captions, library-level
token authentication, IP verification, referrer controls, watermarking,
transcription, heatmaps, multi-audio, premium codecs, and optional DRM. These
capabilities make Bunny a reasonable second managed-video provider, but each one
must still be mapped and tested against HElbaron's authorization and progress
rules.

- [Bunny Stream video API](https://docs.bunny.net/reference/video_getvideo)
- [Bunny video-library security and delivery settings](https://docs.bunny.net/reference/videolibrarypublic_update)
- [Bunny transcription API](https://docs.bunny.net/reference/video_transcribevideo)
- [Bunny Stream format and caption support](https://docs.bunny.net/docs/stream-best-practices)

### P1.10 Search needs production-scale indexing

**Classification:** Confirmed scale risk.

The lexical fallback can produce sequential scans, and the documented PostgreSQL
trigram index is missing.

**Required work**

- Add `pg_trgm` and appropriate indexes.
- Benchmark with representative catalog and learner data.
- Add slow-query monitoring and result-quality tests for Arabic and English.

### P1.11 Known performance bottlenecks remain

**Classification:** Confirmed scale risks.

- Course-announcement fan-out is synchronous and unbounded.
- Public event listing performs an N+1 speaker lookup.
- First certificate PDF download performs synchronous Chromium rendering.
- The client-rendered application shell has a weak mobile LCP baseline.

**Required work**

- Queue and chunk announcement fan-out.
- Batch/eager-load event speakers.
- Pre-generate and cache certificate PDFs.
- Establish API, database, queue, and Core Web Vitals budgets.
- Load-test enrollment, assessment submission, notifications, and reporting.

### P1.12 AI governance and metering need production hardening

**Classification:** Implemented but unverified.

AI is intentionally disabled in the deployed environment. Per-organization quota
accounting also requires stronger atomicity under high concurrency.

**Required work**

- Configure real providers and embedding stores on staging first.
- Make quota reservation/commit atomic and idempotent.
- Add per-feature budgets, rate limits, cost alerts, and kill switches.
- Define prompt/data retention, privacy, redaction, and deletion policies.
- Audit prompts, model/provider versions, tool usage, and generated outcomes.
- Provide safe user-facing degradation when AI is unavailable.

### P1.13 Outbound webhook network hardening is incomplete

**Classification:** Confirmed security hardening gap.

Delivery re-checks reduce DNS-rebinding risk, but connect-time IP pinning is not
present.

**Required work**

- Resolve and validate destination IPs immediately before connection.
- Pin the validated IP for the connection while preserving the host/TLS name.
- Block private, loopback, link-local, metadata, and prohibited networks.
- Apply size, redirect, timeout, retry, and concurrency limits.

### P1.14 White-label implementation is inconsistent

**Classification:** Confirmed product gap.

- Transactional email templates use the application name rather than all admin
  brand settings.
- Some marketing and translation resources hardcode HElbaron branding.
- Contact/location values are duplicated in frontend configuration.
- Certificate branding has overlapping configuration sources.

**Required work**

- Establish a single tenant-aware branding service and DTO.
- Apply it to web, admin, email, certificates, notifications, and metadata.
- Keep translatable values locale-aware and provide safe defaults.

### P1.15 SEO coverage is incomplete

**Classification:** Confirmed product gap.

- No reliable default or dynamic Open Graph image.
- Course details lack `Course` JSON-LD.
- Breadcrumb UI and `BreadcrumbList` JSON-LD are incomplete.
- Cookie-only locale selection prevents useful locale-specific URLs/hreflang.
- Dynamic catalog detail pages may be absent from the sitemap.

### P1.16 Documentation contains conflicting status claims

**Classification:** Confirmed governance weakness.

Older status documents describe architecture and security states that the current
branch has already changed, including learner cookie authentication, the media
platform, assessments, integrations, and instructor routes.

**Required work**

- Nominate one canonical current-state document.
- Tie status statements to commit, environment, date, and verification evidence.
- Archive or clearly label superseded audits.
- Never equate implementation with production verification.

## P2 — Strategic additions subject to customer validation

These items should not outrank P0/P1 work without a confirmed commercial need.

- SCORM, xAPI, or cmi5 interoperability and package import.
- SAML SSO when target customers cannot use OIDC.
- Learning paths, competencies, prerequisites, mastery, and recertification.
- Advanced cohort, organization, compliance, and learning-outcome analytics.
- Native mobile applications and controlled offline learning.
- Enterprise data exports, retention, legal hold, and deletion workflows.
- Public versioned APIs, scoped API clients, and enterprise webhooks.
- Exam proctoring and plagiarism integrations.
- Media watermarking/DRM and regional delivery controls.

## Capability coverage matrix

| Capability | Current evidence | Main remaining work |
|---|---|---|
| Identity/authentication | Implemented | Full MFA/reset/device/session E2E; remember-me correction |
| Admin | Implemented and production styling fixed | Full role/resource/accessibility validation |
| Catalog/authoring | Implemented | Complete launch content and instructor E2E |
| Enrollment/progress | Implemented; free journey tested | Paid, expiry, refund, concurrency, and cross-device tests |
| Video/media | Strong abstraction exists | Real provider, Bunny adapter, token refresh, failure UX, captions workflow |
| Assessments | Core auto-grading implemented | Manual/richer types, accommodations, analytics, moderation |
| Assignments/grading | Files, rubrics, manual grading, gradebook exist | Complete production journey and scale/concurrency evidence |
| Commerce | Implementation exists | Real-provider checkout/refund/webhook/reconciliation evidence |
| Certificates | Implementation exists | Queue/pre-generation, full journey, revocation evidence |
| Live learning | Implementation exists | Real reminder delivery and real meeting-provider journey |
| Notifications | Platform exists | Real email/SMS/push delivery and preference/failure testing |
| AI | Platform exists; production disabled | Real provider, atomic metering, governance, cost and privacy controls |
| SSO | OIDC implementation exists | Live-provider evidence; SAML only if required |
| Search | Platform exists | Indexing, Arabic/English relevance, load and query monitoring |
| Analytics/reporting | Implementation exists | Data-quality reconciliation, permissions, load, and business validation |
| CRM/organizations | Implementation exists | Clarify B2B organization vs true SaaS tenancy |
| Multi-tenancy | Foundation/partial scope | End-to-end isolation, provisioning, quotas, billing, operations |
| Automation/digests | Inert implementation pieces | Execution path, scheduler, retries, audit, or remove/hide |
| Internationalization/RTL | Arabic/English support exists | Complete content, formatting, accessibility, visual regression |
| White-label/SEO | Partial | Central branding, metadata, structured data, localized URLs |
| DevOps/security | Containers and health checks exist | Full CI, restore/rollback drill, alerts, scans, credential rotation |

## Features that already exist and should not be requested again

The following are present in the current branch; backlog work should focus on
validation, completion, and enhancement rather than duplicating them:

- Instructor workspace and instructor routes.
- Assessments and core auto-graded question types.
- Assignments, file submissions, rubrics, manual grading, and gradebook.
- Media platform with ingestion/playback abstractions and signed-delivery code.
- AI, search, notification, and integration platform modules.
- OIDC SSO support.
- CRM and organization/B2B capabilities.
- Versioned APIs for the reviewed learning/assessment areas.

## Recommended delivery order

1. Repair CI/database test execution and make all gates required.
2. Configure production-like staging with real sandbox providers.
3. Complete real video and payment journeys.
4. Complete the full learner, instructor, assessment, assignment, certificate,
   notification, and live-session E2E suite.
5. Demonstrate backup restore, rollback, queue recovery, monitoring, and alerts.
6. Prepare and validate bilingual launch content.
7. Fix confirmed P1 defects: remember-me, automation visibility, real-provider
   delivery, search indexes, performance bottlenecks, and documentation truth.
8. Implement richer exams and enterprise tenancy/RBAC according to signed
   customer requirements.
9. Schedule P2 features only after production reliability gates remain green.

## Definition of done for production readiness

HElbaron may be described as production-ready when all of the following are true:

- No fake provider is enabled for an advertised production feature.
- At least one real bilingual course passes video, quiz, assignment, payment,
  completion, and certificate journeys end to end.
- Full backend, frontend, architecture, and browser suites pass in protected CI.
- Provider callbacks and failure/recovery paths are tested and observable.
- Backup restoration, rollback, and queue recovery have been demonstrated.
- Security scanning, secret rotation, monitoring, and alerts are operational.
- Mobile, RTL, accessibility, and supported-browser gates pass.
- Tenant and role authorization tests match the marketed deployment model.
- Documentation accurately describes the deployed commit and configuration.

## Architecture evidence from the learning code graph

### Core high-connectivity nodes

1. `MediaAsset` — 67 edges.
2. `Assignment` — 57 edges.
3. `Assessment` — 54 edges.
4. `AssignmentSubmission` — 45 edges.
5. `AssessmentQuestion` — 44 edges.

These are the most important regression and architecture-review areas when new
learning features are added.

### Positive structural evidence

- `SaveQuestionAction` delegates question-shape enforcement to
  `QuestionShapeGuard`.
- Assignment, gradebook, submission-review, and learner-submission controllers
  delegate business behavior to services.
- No import cycles were detected in the focused graph.

### Main architecture questions

- Should shared media be split into clearer ingestion, asset-management, and
  playback boundaries before it accumulates more responsibilities?
- Which assessment types are required for the first commercial release?
- Is HElbaron sold as one academy with B2B organizations, or as a true isolated
  multi-tenant SaaS?
- Which providers and enterprise standards are contractual requirements?

## Ongoing audit rule

This document should be reviewed after every major release. A backlog item may be
closed only with a link to implementation, automated tests, production-like
journey evidence, and updated operator documentation.
