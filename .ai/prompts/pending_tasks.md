# Pending Tasks (prioritized)

Format: `[Pn] task — complexity(S/M/L/XL) — depends on`. P1 = do first.

## P1 — decisions / foundations
- [P1] Resolve login-hardening product decision (harden vs keep UX) — S — user input. Unblocks the last open security item.
- [P1] Bootstrap git: first commit of the byte-verified tree + branch strategy — S — user approval to commit (currently forbidden by standing instruction).

## P2 — verification closeout
- [P2] Run authenticated Playwright journeys on the local Windows machine — M — running seeded API + creds (guide §7).
- [P2] Run live security spot-checks (401 envelope, coupon 429, prod-only fake-webhook 404) — S — running API (guide §9).
- [P2] Wire CI to run the 9 gates + Playwright/axe on push — M — git bootstrap (P1).

## P3 — next feature/ops wave (choose track in next_wave.md)
- [P3] W08 Track A: Engagement & Social (Search, Discussions, Reviews, Wishlist, Gamification) — XL — new context scaffolding; EntitlementPort.
- [P3] W08 Track B: DevOps/CD + backups + monitoring + staging — L — infra access; CI (P2).

## P4 — deferred non-blocking polish
- [P4] i18n low-traffic aria-labels (pagination/breadcrumb/video-modal/course-preview-card) — S.
- [P4] Events tablist roving tabindex — S.
- [P4] HTTP contract tests for Filament admin panels — M.
- [P4] Normalize webhook `amountMinor` for precise provider-initiated partial refunds — M — commerce webhook flow.
- [P4] PaymentRecoveryService feature tests (window/backoff/abandon) — S.
