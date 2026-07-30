# Pending Verification

Honest list of what has NOT been verified against the live repo/gates yet. Clear an item only
after actually confirming it; move confirmed facts into `project_state.json`.

## Open
- [ ] PV-01 — Full post-W04 gate run not captured. Run the gates in `local_checks.md` at HEAD
      `ed960b1` and append the result to `gate_history.json`.
- [ ] PV-02 — Untracked working-tree files not enumerated (slow NTFS bind mount). Confirm there
      is no unexpected untracked source under `apps/` with `git status` locally.
- [ ] PV-03 — W01-W03 wave boundaries/titles are inferred from git history + docs/implementation/reports.
      Cross-check against wave tags/commits and set `verified: true` in `project_state.json`.
- [ ] PV-04 — W05 scope not reconfirmed against `docs/redesign/100_EXECUTION_BACKLOG.md`; the
      `_w05_removed/` artifacts have not been diffed against `main` to decide re-apply vs rebuild.
- [ ] PV-05 — `.ai/` is created in the working tree but not committed. Decide whether to commit it
      (recommended, so the layer is versioned and MCP reads a tracked path).

## Cleared
(none yet)
