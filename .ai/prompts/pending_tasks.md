# Pending Tasks — Executable Backlog

Small, directly-executable tasks not tied to the main wave prompt. Newest first. When one is
done, delete its line and reflect the result in `../context/project_state.json`.

- T-01 — Decide fate of `_w05_removed/` (re-apply cleanly vs discard); record decision in decisions.md.
- T-02 — Capture a full gate run at HEAD `ed960b1` into `../verification/gate_history.json` (clears PV-01).
- T-03 — Confirm W01-W03 wave boundaries from git tags/history; set `verified: true` (clears PV-03).
- T-04 — Commit the `.ai/` layer to git so MCP reads a tracked path (clears PV-05).
- T-05 — (tech debt, high) Convert course-announcement fan-out to a queued, chunked job (KI-01).
- T-06 — (tech debt) Batch the public Events speaker lookup to kill the N+1 (KI-02).
- T-07 — (tech debt) Pre-generate first certificate PDF on `CertificateIssued` (KI-03).
