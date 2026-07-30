# Architecture

Derived index. Authoritative sources: `corelms/docs/adr/INDEX.md`,
`corelms/docs/redesign/`, `corelms/apps/api/CoreLMS_C4_Architecture.md` equivalents,
and the code itself. Do not restate those docs here — link and summarize only.

## Stack
- Backend: Laravel 12 (PHP ^8.3) modular monolith — `apps/api`
- Admin: Filament v4 (UI only; ADR-04) at `/admin`, gated by `canAccessPanel()` + optional `EnforceAdminMfa`
- Frontend: custom Next.js 15 / React 19 — `apps/web` (design tokens, light/dark, RTL/LTR, i18n)
- Database: PostgreSQL
- Cache/Queue: Redis + Horizon (`predis`)
- Storage: S3 + CloudFront (`league/flysystem-aws-s3-v3`), signed expiring URLs only
- Video: Mux (signed playback)
- Payments: Stripe (charge/refund/webhook signature)
- Messaging: Mailgun / Twilio / Firebase (FCM) — behind provider ports with fakes as test default
- Errors/monitoring: Sentry
- Auth: Laravel Sanctum, token-only (`sanctum.guard = []`); logout revokes token + device
- AuthZ: `spatie/laravel-permission` + `bezhansalleh/filament-shield`
- API: REST only, versioned `/api/v1` (ADR-17). No GraphQL.

## Boundaries (enforced)
- Bounded contexts under `App\Domains\*`, `App\Contexts\*`, `App\Platform\*` with
  single-writer ownership (ADR-02). Cross-context access via ports only — never direct
  cross-context Model use.
- Enforced in CI by Deptrac (baseline) + custom PHPStan architecture rules (ADR-19),
  plus an ADR-link check (`scripts/adr-link-check.sh`, `.github/workflows/adr-validation.yml`).
- Identity exposes a contracts seam; contexts depend on `IdentityContracts` only (ADR-20).
- Row-level multi-tenancy via a global scope; no manual `org_id` where clauses (ADR-07).
- Media Platform owns bytes; contexts own references (ADR-08).

## Domains (10)
Identity, Catalog, Authoring, Learning, Commerce, Certification, Live, CRM, Analytics, Notifications.
Each: models, services, actions, events, policies, REST v1, Filament resources, factories, seeders, Pest tests.

## Shared foundation
Standard success/error envelope with correlation ids, value objects, enums, base classes,
UUIDv7 public ids. Frontend: shadcn-style component library, TanStack Query, typed API client,
auth context, route guards.

## Removed / not used
LearnHouse, NestJS, FastAPI. (The top-level `corelms/README.md` and the sibling
`../corelms-api/` describe a superseded hybrid arc — treat as historical, not current.)

## Authoritative pointers
- Decisions: `docs/adr/INDEX.md` (ADR-01..20)
- Backlog / wave scope: `docs/redesign/100_EXECUTION_BACKLOG.md` (Sprints/Epics A1..G5)
- Redesigns: `docs/redesign/01..05_*.md`, `docs/redesign/99_IMPLEMENTATION_MASTER_PLAN.md`
- Known limitations / tech debt: `KNOWN_LIMITATIONS.md`
