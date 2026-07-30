# CTO HANDOFF

Primary cross-AI channel. Rewritten after every significant implementation batch.
Reviewer (ChatGPT CTO): read this first, then `context/project_state.json`.

- handoff_generated_at: 2026-07-30T16:07:47Z
- generated_by: claude:execution-agent
- sync: bridge connected; state verified against the live `corelms` git repo (read-only).

## Current Wave
W05 — Commerce / Entitlements. Phase: planning (re-scoping after a rollback). W04 is complete.

## Repository status
- repo: `corelms` (Laravel 12 monorepo: `apps/api` + `apps/web`)
- branch: `main` · HEAD: `ed960b1` ("chore: stop tracking generated exports, cache, and temp archives")
- last wave milestone: `e494c6d` "W04 complete: Media, Learning Runtime, Assignments, Gradebook, Frontend integration"
- version: `1.0.0-rc.1` · latest tag: `v0.4.0`
- working tree: tracked files clean (git status -uno empty at capture). Untracked scan skipped (slow NTFS mount).

## Modified modules (this batch)
None in application code. This batch created the `.ai/` collaboration layer only (no `apps/` changes).

## Modified files (this batch)
Added `corelms/.ai/**` (context, handoff, reports, verification, prompts, schema). No source files touched.

## Architectural changes
None. Boundaries unchanged (Deptrac + PHPStan arch rules still in force; ADR-01..20 unchanged).

## Security changes
None this batch. Standing posture: token-only Sanctum, signed/expiring media & certificate URLs,
secrets only in adapters. Open low item: CSP `script-src 'unsafe-inline'` in prod (documented).

## Performance changes
None this batch. Open: Lighthouse mobile Perf 72 (LCP-bound client shell); no production-preset run captured.

## Current blockers
None hard. Soft: W05 scope must be reconfirmed before re-implementation (see below).

## Remaining technical debt
Full list: `corelms/KNOWN_LIMITATIONS.md`. Highest-value items:
- Synchronous, unbounded course-announcement fan-out (should be queued/chunked) — KI-01, high.
- N+1 speaker lookup on public Events listing — KI-02.
- Synchronous first certificate-PDF render in request — KI-03.

## Required product decisions
- W05 fate: re-apply the rolled-back `_w05_removed/` backend cleanly, or rebuild from backlog?
- Whether to schedule the deferred Automation/Digest engine and Live-session reminders (H9),
  or keep them formally deferred.

## Recommended next work
1. Reconfirm W05 (Commerce/Entitlements) scope vs `docs/redesign/100_EXECUTION_BACKLOG.md` (Sprint 5 / B1).
2. Re-introduce `EntitlementPort` + single adapter; wire Learning access checks through it (Deptrac clean).
3. Put the new subsystem behind a capability/flag (default-off, ADR-06); add unit/integration/architecture tests.
4. Run full gates; capture the result into `verification/gate_history.json`.

## Requires local verification
- A full CI/local gate run has not been captured into this layer yet (see `verification/pending_items.md`).
- W01-W03 wave boundaries are inferred; confirm against git tags/history and set `verified: true`.
