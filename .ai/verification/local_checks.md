# Local Windows Verification — pending checks (append-only)

Anything that requires the user's Windows machine goes here. **Append; never delete.** Full
copy-paste commands live in `docs/verification/W07_LOCAL_WINDOWS_VERIFICATION.md`; this file is the
index of what is still outstanding and why.

Local repo (per user): `D:\Claude_Files\Projects\LMS` (actual repo root: `...\CoreLMS Implementation\corelms`).
The cloud sandbox has no access to PowerShell or the user's local services; nothing below has been
verified by the AI — it is LOCAL VERIFICATION REQUIRED.

---

## [W07] Authenticated browser journeys (Playwright)
- **Reason:** The sandbox ran the public E2E + axe (PASS) but the authenticated legs are skipped without a running seeded API + credentials.
- **Working directory:** `apps\web` (API from `apps\api` must be running).
- **Commands:** guide §7 — set `PLAYWRIGHT_BASE_URL`, `E2E_EMAIL`, `E2E_PASSWORD`, then `npx playwright test --project=chromium e2e/smoke.spec.ts`.
- **Expected:** `3 passed` incl. `authenticated journey: login -> dashboard -> logout`.
- **Failure symptoms:** hangs at `waitForURL(/dashboard|my-learning/)` → API not reachable or creds wrong.
- **Send back:** `apps\web\playwright-report\index.html` + console output.

## [W07] Live-service security spot-checks
- **Reason:** Rate-limit trip (429), unauthenticated 401 envelope, and the production-only absence of the fake media webhook can only be observed against a running API.
- **Working directory:** any (API running on :8000).
- **Commands:** guide §9 (curl loops).
- **Expected:** (a) 401 JSON envelope; (b) ~10×422/404 then 429; (c) fake webhook → 404 when `APP_ENV=production`.
- **Send back:** the printed status codes; `apps\api\storage\logs\laravel.log` on anomaly.

## [post-W07] Materialize the .ai/ collaboration layer into the repo — DONE
- The `.ai/` tree (15 files) was written directly to `...\CoreLMS Implementation\corelms\.ai\` via the desktop bridge and verified byte-identical (SHA-256, 15/15). No manual action needed.

## [W07] Full local gate reproduction (optional confidence)
- **Reason:** All 9 gates passed in the sandbox against the real code; reproducing locally confirms parity with the user's exact toolchain/services.
- **Commands:** guide §2–§5.
- **Expected:** backend `Tests: 808 passed`, PHPStan `[OK]`, Deptrac `0/0`, Pint passed; frontend typecheck/lint(0 errors)/vitest(484)/build all exit 0.
- **Send back:** the failing command's full output + relevant log if any gate differs.
