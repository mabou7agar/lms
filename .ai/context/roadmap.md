# Roadmap

Wave sequence for the redesign-execution arc, running on top of the v1.0.0-rc.1 baseline.
Authoritative scope per wave lives in `docs/redesign/100_EXECUTION_BACKLOG.md`
(Sprint -> Epic -> Story -> Task, epic ids A1..G5). This file is the short index only.

## Legend
done = merged + gates green · active = in progress · next = queued · planned = scoped, not started

## Sequence
- [done]   Sprint 0 — Architecture Fitness & Tooling (Deptrac, PHPStan arch rules, ADR-link check; ADR-19/20)
- [done]   W01 — Identity (row-level multi-tenancy, contracts seam, policies/gates)      *(boundary unverified)*
- [done]   W02 — Catalog / Curriculum (content versioning migration; ADR-09)             *(boundary unverified)*
- [done]   W03 — Authoring / Assessment / Publishing (Course Builder, grading engine, publish-readiness engine)
- [done]   W04 — Media, Learning Runtime, Assignments, Gradebook, Frontend integration   *(commit e494c6d)*
- [active] W05 — Commerce / Entitlements (EntitlementPort: paid grants UNION subscriptions; ADR-06/11)
- [next]   W06+ — derive from backlog (Progress projector ADR-10; Certification/LRS ADR-12/13; Live; CRM; Analytics ADR-18; Integration Platform ADR-16; Offline ADR-15; AI ports ADR-14)

## Notes
- W numbering here follows the team's commit-wave labels (git uses `W04 complete`, etc.).
  The backlog labels the same units as Sprints/Epics (A/B/C...). Keep both cross-referenced;
  do not renumber committed waves.
- Post-baseline deferrals (NOT in any current wave until formally scheduled): Automation/Digest
  engine, Live-session reminders (H9). See `context/project_state.json > deferred_subsystems`.
