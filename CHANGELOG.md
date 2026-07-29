# Changelog

All notable changes to HElbaron are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/); versioning is [SemVer](https://semver.org/).

## [1.0.0-rc.1] - 2026-07-05

First tagged Release Candidate of HElbaron v1.0.0. Adds production deploy/rollback scripts,
an environment-validation command, and a LICENSE. No API or business changes.

## [1.0.0] - 2026-07-05
First production release candidate. A bilingual (AR/EN) enterprise LMS: Laravel 12 modular
monolith API + custom Next.js 15 frontend, PostgreSQL, Redis/Horizon, S3/CloudFront, Mux.

### Added
- **Domains (10):** Identity, Catalog, Authoring, Learning, Commerce, Certification, Live,
  CRM, Analytics, Notifications — each with models, services, actions, events, policies,
  REST API (v1), Filament resources, factories, seeders, and Pest tests.
- **Shared foundation:** standard success/error envelope with correlation ids, value objects,
  enums, base classes, UUIDv7 public ids.
- **Frontend foundation (Next.js 15/React 19):** design tokens + light/dark + RTL/LTR, i18n,
  shadcn-style component library, TanStack Query, typed API client, auth context, route guards.
- **External integrations (Step 14):** Stripe (charge/refund/webhook signature), Mux signed
  playback, S3 + CloudFront signed URLs, Mailgun / Twilio / Firebase — all behind provider
  abstractions with fakes as the local/test default.
- **Production hardening (Step 15):** security headers + CSP + HSTS, correlation-id middleware,
  trusted proxies/hosts, secure cookies, restricted CORS, structured JSON logging,
  liveness/readiness health checks, tuned Horizon + queue config, production Docker image +
  compose + nginx, CI (Pint/PHPStan/Pest + Node build), and the full ops documentation set.

### Security
- Token-only Sanctum auth (`sanctum.guard = []`); logout revokes token + device.
- Media/certificate/export access only via signed, expiring URLs — storage keys never exposed.
- Provider secrets read only by adapters; nothing committed.

### Notes
- Filament v4 admin panel (`/admin`) is **registered** (`AdminPanelProvider`) with per-domain
  resource + widget discovery; access is gated by `canAccessPanel()` (super_admin/admin) and,
  when `ADMIN_REQUIRE_MFA`, by `EnforceAdminMfa`.
- Firebase push uses FCM legacy HTTP; HTTP v1 planned.
- The automation/digest engine is present but unwired and **deferred** for v1.0 (see
  `KNOWN_LIMITATIONS.md`); live-session reminders are written but not yet delivered (H9, deferred).
