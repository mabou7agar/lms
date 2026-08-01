# Proposed next wave prompt

Two gating items should be settled first (neither is a large wave):

1. **Login hardening — product decision.** Reply with one of: (a) HARDEN — uniform 401 auth
   responses + decaying/backing-off lockout keyed on (email+IP), updating the auth tests to assert
   the corrected behavior; or (b) KEEP — leave the current per-status UX and mark the item accepted.
2. **Version control bootstrap (recommended).** Establish git: first commit of the current
   byte-verified tree, a branch strategy, and CI wiring that runs the 9 gates + Playwright/axe. This
   ends the "no history" technical debt before more features land.

---

## W08 (proposed) — pick ONE track

### Track A — Engagement & Social (the explicit W05 carve-outs)
Search/Discovery, Discussions/Q&A, Reviews & Ratings, Wishlist, Gamification. New bounded
context(s); reuse EntitlementPort for gating; keep money/idempotency rules where relevant.

### Track B — DevOps / Production Readiness (master-roadmap Groups G/H)
CD pipeline (push image + deploy + rollback), automated backup/restore, monitoring/alerting,
staging environment, and CI that enforces all gates. Turns "gates pass locally" into "gates gate
merges."

## Standard execution rules for the wave (reuse)
- Independent read-only auditors first → dedupe → reproducible defects only → fix continuously.
- Keep all 9 gates green after every meaningful batch; re-run only affected gates during work.
- No fake green; no baseline weakening; no test deletion; money in integer minor units; idempotency
  on payments/orders/refunds/subscriptions/webhooks.
- Update `.ai/` continuously (project_state.json, current_wave.md, decisions.md, gate_history.json,
  byte_identity.json, CTO_HANDOFF.md). Add a `reports/W08.md` at close.
- Sync changed files to the device and verify byte-identity. Record anything needing the local
  Windows machine in `.ai/verification/local_checks.md`.
- Final response: the `W08 FINAL VERIFICATION` block (resolved items, gate PASS lines, byte-identity,
  local-verification status).
