# Changelog

All notable changes to HELBARON LMS are documented here. This project follows
semantic versioning; pre-release builds use `-rc.N` suffixes.

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
