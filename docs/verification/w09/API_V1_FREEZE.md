# API v1 Freeze Summary — 1.0.0-rc.1

The public `/api/v1` surface is **frozen** for the release candidate. Any change after
this point must update backend routes, frontend consumers, tests, and the domain OpenAPI
spec together, and preserve backward compatibility unless a breaking change is explicitly
versioned.

## Envelope contract (stable)
- Success: `{ "data": ... }` (via `ApiResponse::success`).
- Paginated: `{ "data": [...], "meta": {...}, "links": {...} }` (via `ApiResponse::paginated`).
- Error: `{ "error": { "code": <STABLE_CODE>, "message": ..., "details"?: ..., "correlation_id"?: ... } }`
  (every domain exception extends `BaseDomainException`; codes are stable machine-readable
  strings such as `COMMERCE_CART_EMPTY`, `COMMERCE_CHECKOUT_IN_PROGRESS`).

## OpenAPI specs (per bounded context / domain)
Present and versioned under the codebase:
`Catalog`, `Authoring`, `Learning`, `Commerce`, `Analytics`, `Certification`, `Live`,
`Crm`, `Identity`, `Notifications`, `Media` (`*/openapi/*.yaml`). These are the source of
truth for the frozen v1 contract.

## W09 contract reconciliation
- **W09-D1 (fixed):** the web media / assignments / versioning / gradebook / learning-player
  clients called `/api/v1/v1/...` (double prefix) → 404. Frontend consumers are now aligned
  to the real backend routes (`/api/v1/...`). Guard: `apps/web/tests/contract/no-double-v1-prefix.test.ts`.
- No backend route signatures changed in W09; the fix was purely on the consumer side, so no
  OpenAPI edits were required. The checkout change (W09-D2) added a new **error code**
  (`COMMERCE_CHECKOUT_IN_PROGRESS`, HTTP 409) on the existing `POST /api/v1/checkout` route —
  additive, backward-compatible (a new failure mode, no shape change).

## Freeze assertions (to re-run on any future v1 change)
- No frontend fetcher targets a path that doubles the version prefix (regression test above).
- Pagination responses carry `data` + `meta` + `links`; error responses carry `error.code`.
- Error codes are stable and enumerated by the domain exceptions.

## Not frozen
Internal routes (`/api/session`, `/api/backend/*` BFF proxy), admin Filament panel, and
webhook ingress are implementation details, not part of the public v1 contract.
