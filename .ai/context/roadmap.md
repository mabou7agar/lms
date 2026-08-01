# Roadmap (AI context)

History is append-only. Never overwrite completed waves.

## Wave numbering note

"W##" is the execution-orchestration numbering used in this project's AI-driven build. It is distinct
from the audit-derived Phase/Group roadmap in `docs/audits/10_MASTER_IMPLEMENTATION_ROADMAP.md`.

## Completed Waves

### W05 — Commerce Mega-Wave (Commerce Foundation) — DONE
One unified commerce stack: Payments, Tax, Subscriptions, Refunds, Credit Notes, Invoices,
Entitlements, Coupons/Promotions, Commerce Analytics, MENA gateways (Paymob/Moyasar/HyperPay/Tap/
Amazon Payment Services/Stripe), checkout, orders, webhooks, learner billing portal, admin commerce,
frontend + tests. Excluded: Search/Discussions/Reviews/Wishlist/Gamification.
Result: all 9 gates green; 263 backend + 3 frontend files; byte-identical on device. No git commit.

### W06 — Launch-Blocker Hardening — DONE
AuthZ/tenant/IDOR, navigation & route integrity, data integrity & failure recovery, security
hardening, production readiness, accessibility/RTL/i18n, performance (pagination), tests.
Highlights: quiz IDOR gate, payment charge-outside-tx + idempotency key, onOneServer schedulers,
fail-closed trusted proxies, coupon rate-limit, media fake-webhook prod removal, job timeout/failed,
list-endpoint pagination, gradebook CSV streaming, commerce workspace nav + mobile hamburger,
teach/apply reachability, RTL logical props. All 9 gates green; 39 files byte-identical on device.

### W07 — Independent QA & Verification — DONE
Adversarial read-only audits → deduped reproducible defects → fixed continuously, gates kept green.
Fixed: admin paginated-envelope crash (5 controllers), Postgres dunning crash (FOR UPDATE + aggregate),
completed-enrollment attempt lockout, partial-refund-as-full webhook, charge-ledger corruption on
retries, invoice-line discount reconciliation, invoice-number race, coupon-cap dunning escape,
certificate forgery via legacy progress endpoint, forced video completion, coupon-scope contract
mismatch, unclamped per_page, OTP guess ceiling, mobile-drawer-not-closing, instructor-nav leak on
/teach/apply, double aria-current sidebar, hardcoded a11y names, dead footer control.
Added: Playwright E2E (chromium public) + axe a11y to the runnable gate set. 808 backend tests
(+5), 484 frontend, all 9 gates + 2 additional QA gates green. 31 files byte-identical on device.
Deferred to product decision: login enumeration/lockout-DoS hardening.

## Current Wave

### Post-W07 — AI Collaboration Layer — IN PROGRESS
Installing `.ai/` persistent machine-readable context (this directory). No product code changes.

## Remaining / Proposed Waves

- **Login/auth hardening (product decision pending)** — uniform auth responses + decaying lockout, or keep current UX. See project_state.requires_product_decision.
- **W08 (proposed)** — see `.ai/prompts/next_wave.md`. Candidate scope: version-control bootstrap (first commit + branch strategy + CI wiring of the 9 gates), then the next feature mega-wave from the excluded W05 set (Search/Discovery, Discussions/Q&A, Reviews & Ratings, Wishlist, Gamification) OR DevOps/CD + monitoring + backups from the master roadmap Groups G/H.

## Major milestones

- [x] Commerce foundation shippable (W05)
- [x] Launch blockers eliminated (W06)
- [x] Independent QA pass, reproducible defects fixed (W07)
- [ ] Version control established (no commits yet)
- [ ] Authenticated E2E verified on local Windows machine
- [ ] Production CD / monitoring / backups
- [ ] Beta launch
