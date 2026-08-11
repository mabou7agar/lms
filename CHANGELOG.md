# Changelog

All notable changes to HELBARON LMS are documented here. This project follows
semantic versioning; pre-release builds use `-rc.N` suffixes.

## [1.0.0-rc.2] — 2026-08-12

Hardening and production-closure candidate. Carries the full Stage-4 enterprise / AI /
growth / integrations feature set (enterprise manager portal, RAG semantic search &
recommendations, AI tutor & instructor copilot, admin analytics assistant, public
developer API, SSO operations, marketing automation, per-org white-label branding &
custom domains, org BI export/import — see `STAGE_4_REPORT.md`) plus the security,
reliability and correctness fixes below. **Code-only advance: no new database
migrations over the Stage-4 schema** (Stage-4 migrations are catalogued in
`docs/releases/DEPLOYMENT_MANIFEST_v1.0.0-rc.1.md` and the module `Database/Migrations`
directories).

### Security (confirmed defects — fixed)
- **Scoped developer API keys were valid on the entire first-party API.** Sanctum's
  `auth:sanctum` guard authenticates any valid personal-access-token regardless of its
  abilities; per-ability enforcement existed only on `/api/v1/developer/*`. A read-only
  developer key (e.g. `account:read`) was therefore a valid bearer on every other
  `auth:sanctum` route, silently exercising the owner's full permissions. New
  `EnforceApiTokenScope` middleware confines any token lacking the full-access `*`
  ability to the developer surface (and key-management), returning `403
  TOKEN_SCOPE_FORBIDDEN` elsewhere. First-party login tokens (`*`) and SPA-cookie
  sessions are unaffected. Guarded in `DeveloperScopeEnforcementTest`.
- **Webhook SSRF: alternate IP encodings and redirect bypass.** The outbound-webhook
  URL guard validated only the literal host of the registered URL. Decimal/hex/octal
  IPv4 encodings (`2852039166`, `0x7f.0.0.1`, `0177.0.0.1`) and IPv4-mapped IPv6
  literals (`::ffff:169.254.169.254`) slipped past the private-range check, and a
  delivery could follow a 3xx redirect to an internal address. The guard now rejects
  numeric-host literals and decodes IPv4-mapped/compatible IPv6 before the private-range
  test; delivery uses `withoutRedirecting()`. Guarded in `WebhookUrlGuardTest`.
- **`POST /auth/mfa/disable` was unthrottled.** Added the `identity-otp-verify` rate
  limiter, matching MFA enable/verify.

### Reliability & correctness (confirmed defects — fixed)
- **Coupon expiry / deactivation was not re-checked at checkout.** A cart with a
  persisted coupon that had since been deactivated or moved out of its validity window
  would still discount at checkout (a revenue leak). Checkout now re-validates
  `is_active`, the validity window and exhaustion under the coupon row lock
  (`CouponInvalidException` / `CouponExpiredException` / `CouponExhaustedException`).
- **Drip campaigns could double-send a step across a crash.** The runner recorded a
  send only after the provider call, so a crash between send and record re-sent the step
  on the next tick. It now writes an in-flight `Sending` claim before the provider call;
  the crash-safety guard treats a leftover claim as already handled.
- **Semantic-search index retained orphaned embeddings.** `rebuildAll` reindexed
  eligible content but never purged embeddings for content that had become
  unpublished/deleted. It now purges rows whose source is no longer indexable, and a
  daily `search:backfill` schedule keeps the index converged.
- **`continue learning` rail was unbounded.** The learner endpoint now orders by recent
  activity and caps at 24 enrollments, bounding the per-enrollment next-lesson lookups.

### Config safety (confirmed defects — fixed)
- **Production media guard was a dead no-op** (read a non-existent `media.provider`
  key); it now reads the real `media.ingestion.default`. Added a guard rejecting the
  `fake` mail/SMS/push transports in production unless `NOTIFICATIONS_ALLOW_FAKE=true`.
  Guarded in `ProductionConfigValidatorTest`.

### Notes
- No push, no image publish, and no release tag are created by this candidate — those
  steps await explicit authorization after gate evidence. Items that require the
  operator's own environment or live credentials (container image scan, browser/a11y
  runs, backup-restore drill, live-provider smoke) are listed in
  `docs/releases/v1.0.0-rc.2.md` and are NOT claimed as passing here.

## [1.0.0-rc.1] — 2026-08-01

First release candidate. Cumulative of waves W01–W09 (bilingual EN/AR RTL MENA LMS:
catalog, learning, authoring, assessment, assignments, certification, commerce,
subscriptions, CRM, notifications, analytics; Laravel 12 API + Next.js 15 web).

### Fixed (W09 release-blocking)
- **Web API client double-versioned every authoring/media/grading/player request.**
  The media, assignments, versioning, gradebook and learning-player modules prefixed
  paths with `v1/`, but the BFF proxy base already ends in `/api/v1`, so requests
  resolved to `/api/v1/v1/...` and returned 404 in every environment — silently
  breaking the entire instructor-authoring, media, grading and lesson-player surface.
  Paths are now bare (matching the working majority). Guarded by
  `apps/web/tests/contract/no-double-v1-prefix.test.ts`.
- **Checkout could double-charge on a duplicate submit.** Two rapid `POST /checkout`
  requests (double-click / concurrent) each created a separate order with a distinct
  gateway idempotency key, producing two charges from one cart. Checkout is now
  serialized per user with a distributed lock held across the gateway call; a queued
  duplicate re-reads the emptied cart and is safely rejected (409
  `COMMERCE_CHECKOUT_IN_PROGRESS`, or 422 `COMMERCE_CART_EMPTY` once captured). Guarded
  by two regression tests in `tests/Feature/Commerce/CartCheckoutTest.php`.

### Changed
- Web `postcss` → 8.5.x and `sharp` → 0.35.x (W08 security remediation; both images
  now scan clean).
- Application version set to `1.0.0-rc.1`.

### Notes
- No database schema changes in W09 — this candidate is a code-only advance over W08.
- See `docs/releases/v1.0.0-rc.1.md` for release notes, rollback and known limitations,
  and `docs/verification/w09/` for the UAT matrix and gate evidence.
