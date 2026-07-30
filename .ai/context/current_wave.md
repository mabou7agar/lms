# Current Wave

## Wave
W05 — Commerce / Entitlements

## Phase
Planning (re-scoping after rollback)

## Status
In progress. W04 is complete and merged; the tracked working tree is clean at `ed960b1` on `main`.

## Goal
Introduce the Commerce entitlement boundary that Learning (and other contexts) consume to
gate course access. The seam is a scalar-in / scalar-out port so consumers never import
Commerce Eloquent models.

## Known scope signal (from rolled-back attempt)
`App\Contexts\Commerce\Contracts\EntitlementPort`:
- `hasCourseEntitlement(int userId, int courseId): bool`
- `entitledCourseIds(int userId): list<int>`
Backing: paid one-off order grants (`OrderCourseGrant`) UNION active subscriptions.

## Important state
A first W05 backend implementation was staged on 2026-07-29 and then rolled back. The
artifacts are retained (not deleted) at `../../_w05_removed/`:
`EntitlementPort.php`, `w05_backend.tgz`, `w05src.tgz`, `w05_backend.b64`.
Before re-implementing, decide: re-apply cleanly vs. rebuild from the backlog. Reconfirm
scope against `docs/redesign/100_EXECUTION_BACKLOG.md` (Sprint 5 / epic B1) and relevant ADRs
(ADR-06 capability vs permission vs flag; ADR-09 content versioning; ADR-11 Authoring/Learning).

## Exit criteria (proposed — confirm against backlog)
- EntitlementPort defined in Commerce; a single adapter binds it.
- Learning depends on `IdentityContracts` / Commerce contracts only (Deptrac clean).
- New subsystem behind a capability/flag (default-off) per ADR-06.
- Tests: unit (grant/subscription union), integration (port/adapter + DB), architecture (Deptrac/PHPStan).
- All mandatory CI gates green; no baseline growth (PHPStan/Deptrac); coverage not decreased.
