# Decisions

Two layers of decisions:
1. **Architecture decisions** — authoritative in `docs/adr/INDEX.md` (ADR-01..20). Do not
   duplicate them here; cite by id. Any architecture-sensitive change must reference an ADR
   (enforced by the ADR-link CI check) or add a new one.
2. **AI-collaboration decisions** — meta-decisions about how the two AIs work through `.ai/`.
   Recorded below (append-only; newest last).

## Architecture decisions (index — source: docs/adr/INDEX.md)
- ADR-01 Modular monolith over microservices — Implemented
- ADR-02 Bounded contexts, single-writer ownership — In progress (continuous)
- ADR-03 Event-driven integration (events as DTOs) — In progress
- ADR-04 Filament as UI only — Implemented
- ADR-07 Row-level multi-tenancy via global scope — Foundation
- ADR-08 Media Platform owns bytes; contexts own refs — planned
- ADR-09 Content versioning (copy-on-write + pinning) — planned
- ADR-11 Authoring owns definitions; Learning owns attempts — planned
- ADR-17 REST-only, versioned, Sanctum-authenticated API — Implemented
- ADR-19 Deptrac + custom PHPStan rules for architecture fitness — Implemented
- ADR-20 Identity contracts seam; contexts depend on IdentityContracts only — Foundation
(Full list and status: docs/adr/INDEX.md.)

## AI-collaboration decisions
- AID-01 (2026-07-30, claude) — `.ai/` is the single source of truth for cross-AI collaboration.
  A resuming AI reads only `.ai/`; chat history is never assumed. Location: `corelms/.ai/`
  (git repo root) so the layer is versioned and travels with the code.
- AID-02 (2026-07-30, claude) — This layer is DERIVED, not a fork. When it could conflict with
  git/ADRs/backlog, re-derive from those and rewrite; never let `.ai/` drift into a second truth.
- AID-03 (2026-07-30, claude) — Never fabricate synchronization. If the working tree cannot be
  inspected, mark state pending in `sync` and `verification/pending_items.md` rather than guessing.
- AID-04 (2026-07-30, claude) — Committed wave numbers are immutable. Cross-reference backlog
  epic ids (A/B/C) but do not renumber a wave that already exists in git history.
