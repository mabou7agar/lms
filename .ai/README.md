# .ai — AI Collaboration Layer (single source of truth)

This directory is the shared, machine-first workspace for the AI systems working on
CoreLMS (internal name: HElbaron). It is designed to be exposed over MCP.

**Contract:** Any AI resuming work reads ONLY this directory. Never assume access to
chat history. Everything required to understand and continue the project must exist here.

## Roles
- `claude` — execution agent. Implements waves, runs local/CI gates, rewrites this layer.
- `cto` (ChatGPT) — continuous reviewer. Reads `handoff/CTO_HANDOFF.md` first, then context/.

## Read order (for a resuming AI)
1. `context/project_state.json`  — current machine state (start here)
2. `handoff/CTO_HANDOFF.md`      — latest human-free handoff narrative
3. `context/current_wave.md`     — what is being worked on now
4. `context/architecture.md`     — stack + boundaries (pointers to authoritative docs)
5. `context/roadmap.md`          — wave sequence
6. `context/decisions.md`        — decision log (points to docs/adr/INDEX.md)
7. `verification/*`              — gates, local checks, pending verification
8. `reports/W*.md`              — per-wave change records
9. `prompts/next_wave.md`        — the directly-executable next task

## Write policy (for the execution agent)
Rewrite the affected files after: every major implementation batch, every gate run,
every architectural change, every completed wave. Keep files small, deterministic,
and non-duplicated. Facts live in exactly one file:
- Live machine state → `context/project_state.json`
- Human-free narrative for the reviewer → `handoff/CTO_HANDOFF.md`
- Decisions → `context/decisions.md` (+ repo `docs/adr/INDEX.md`)
- Gate results over time → `verification/gate_history.json`

## Ground truth vs. this layer
The authoritative sources in the repo remain: git history, `docs/adr/INDEX.md`,
`docs/redesign/100_EXECUTION_BACKLOG.md`, `apps/api` (Laravel), `apps/web` (Next.js).
This layer is a *derived, always-current index* of them — never a fork of them. When
in doubt, re-derive from git and rewrite these files; do not let them drift.

## Formatting rules
- JSON: 2-space indent, stable key order, schema-validated (`schema/`).
- Markdown: stable H2 headings (do not rename); append under a heading, don't reflow.
- Timestamps: UTC ISO-8601. Wave ids: `W##`. Never fabricate sync — if the working
  tree could not be inspected, say so in `sync` / `verification/pending_items.md`.

ai_layer_version: 1.0
