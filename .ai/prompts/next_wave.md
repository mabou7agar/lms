# Next Wave — Executable Prompt

Directly executable by the execution agent. Read `../context/project_state.json` and
`../handoff/CTO_HANDOFF.md` first. Do not assume chat history.

---

## Task: Implement W05 — Commerce / Entitlements

You are the senior Laravel + Next.js engineer on CoreLMS (Laravel 12 modular monolith
`apps/api` + Next.js `apps/web`). Branch `main`, HEAD `ed960b1`, tree clean.

### 0. Pre-flight
1. Reconfirm scope in `docs/redesign/100_EXECUTION_BACKLOG.md` (Sprint 5 / epic B1) and read
   ADR-06 (capability vs permission vs flag) and ADR-11 (Authoring owns definitions; Learning owns attempts).
2. Inspect `_w05_removed/` (rolled-back attempt): `EntitlementPort.php`, `w05_backend.tgz`,
   `w05src.tgz`. Diff against `main`. Decide re-apply-cleanly vs rebuild. Record the decision in
   `.ai/context/decisions.md` (new AID-xx) and `.ai/context/project_state.json`.

### 1. Implement
- Define `App\Contexts\Commerce\Contracts\EntitlementPort`:
  - `hasCourseEntitlement(int $userId, int $courseId): bool`
  - `entitledCourseIds(int $userId): array` (list<int>)
- One adapter backs it: union of paid one-off grants (`OrderCourseGrant`) and active subscriptions.
- Learning (and other consumers) depend on the port only — no Commerce Eloquent models across the boundary.
- Put the subsystem behind a capability/flag, default-off (ADR-06).
- Migrations: expand-and-contract only (no destructive single step).

### 2. Test (per canonical story taxonomy)
- Unit: entitlement union logic (grant only / subscription only / both / neither / expired).
- Integration: port + adapter against the DB.
- Architecture: Deptrac + PHPStan rule — Learning must not import Commerce models.
- E2E (if a user-facing gate changes): Playwright access-gate happy/blocked paths.

### 3. Gates (must pass — see ../verification/local_checks.md)
Pint, PHPStan (no baseline growth), Deptrac (no new violation), Pest, ESLint, tsc, Vitest,
Playwright, axe, Trivy. Coverage not decreased. OpenAPI regenerated, no breaking diff.

### 4. Update the AI layer (mandatory)
- Rewrite `.ai/reports/W05.md` to the completed template.
- Append the gate run to `.ai/verification/gate_history.json`.
- Update `.ai/context/project_state.json` (wave.current -> W06 planning; move W05 into completed, verified:true).
- Rewrite `.ai/handoff/CTO_HANDOFF.md`.
- Generate the next `.ai/prompts/next_wave.md` for W06 (derive from the backlog).
- Clear the relevant items in `.ai/verification/pending_items.md`.

### Return contract (per project instructions)
1. Files changed  2. Code  3. Migrations  4. Tests  5. Commands to run  6. Risks/blockers.
