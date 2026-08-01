# Pending Items (live checklist)

Known issues, deferred improvements, manual verification pending, environment limits, future refactors.
Keep current. `[ ]` open · `[x]` done · `[~]` deferred/needs-decision.

## Requires a product decision (blocks nothing else)
- [~] **Login enumeration + email-only lockout DoS** (auth, MED). Distinct 401/403/423 before password check = enumeration oracle; lockout keyed on email alone = targeted DoS. Fix = uniform 401 + decaying lockout, but it removes the "account locked/disabled" UX and rewrites auth tests. Awaiting user's choice (harden vs keep UX).

## Manual verification pending (local Windows)
- [ ] Authenticated Playwright journeys (login→dashboard→logout; dashboard a11y). See local_checks.md / guide §7–§8.
- [ ] Live security spot-checks (401 envelope, coupon 429, prod-only fake-webhook 404). Guide §9.
- [ ] Optional: reproduce all 9 gates locally for toolchain parity. Guide §2–§5.

## Environment limitations
- [ ] No git history — all sync is file-write + SHA-256, no commits (per standing instruction). Recommend bootstrapping git before further waves.
- [ ] Device VM has no PHP — all gates run in the cloud sandbox against the real code.
- [ ] Sandbox-only composer hacks (phpstan local-zip dist; preferred-install=source; use-github-api=false) must NEVER be synced to the device.

## Deferred non-blocking improvements
- [~] i18n of low-traffic aria-labels (pagination, breadcrumb, video-modal, course-preview-card).
- [~] Events tablist roving tabindex (operable via Tab+Enter today).
- [~] Desktop sidebar media-query hydration flash (cosmetic).
- [~] HTTP contract tests for Filament admin panels (currently server-side only).

## Future refactors (candidate)
- [ ] Establish CI that runs the 9 gates + Playwright/axe on every push.
- [ ] Consider a normalized `amountMinor` field on the webhook event so provider-initiated PARTIAL refunds (no prior Refund row) are represented precisely rather than assumed full.
- [ ] Consider adding PaymentRecoveryService feature tests beyond the idempotency-key regression (dunning window, backoff, abandon).
