# W09 — Release Candidate Verification (1.0.0-rc.1)

Concise evidence index for the RC gate. Runnable gates were executed in the cloud
sandbox at the RC code; Docker/browser gates are consolidated into
`W09_WINDOWS_UAT.ps1` and were executed **end-to-end on the Windows release host**.

## Full local UAT — result

`W09_WINDOWS_UAT.ps1` completed **PASS (52/52 gates, 0 failures)** on the Docker release
host at RC HEAD `d4c7881`. Evidence: `evidence/20260801-151751/` (`summary.txt`,
`summary.json`, per-gate logs, `container-logs/`, `playwright-results/`). The single run
covers image builds, Trivy (0 HIGH/CRITICAL), secret scan, full six-service stack health,
`config:validate --strict`, prod-DB `migrate --force`, the backend suite, the frontend
gates, Playwright (functional + a11y + RTL + mobile), correlation-ID propagation,
readiness-degradation + recovery, and the backup/restore drill.

## Gate results (executed this wave)

| Gate | Result | Evidence |
|---|---|---|
| Backend `migrate:fresh --seed` | **PASS** | exit 0; 148 tables |
| Backend Pest (sequential) | **PASS** | 823 passed / 2650 assertions |
| Backend PHPStan (L6 + baseline) | **PASS** | No errors |
| Backend Pint | **PASS** | passed |
| Backend Deptrac | **PASS** | 0 violations / 0 warnings |
| Frontend Typecheck | **PASS** | `tsc --noEmit` clean |
| Frontend Lint | **PASS** | 0 errors (9 pre-existing warnings) |
| Frontend Vitest | **PASS** | 498 passed / 100 files |
| Frontend Build | **PASS** | compiled successfully |
| Config validation (`config:validate --strict`) | **PASS** (release host) | in-container, prod image; rejects placeholder config as designed |
| Prod-DB `migrate --force` | **PASS** (release host) | schema applied to the running prod stack |
| Backup / restore drill | **PASS** (release host) | dump + SHA-256 verify + restore; source tables == restored tables |
| API image build + Trivy | **PASS** (release host) | build OK; 0 HIGH/CRITICAL |
| Web image build + Trivy | **PASS** (release host) | build OK; 0 HIGH/CRITICAL after postcss/sharp bump |
| Secret scan (images + repo) | **PASS** (release host) | Trivy secret scanner, 0 findings |
| CI workflow YAML validation | **PASS** (W08) | ci/deploy/adr/uptime parse clean |
| Container health smoke (6 services) | **PASS** (release host) | postgres/redis/api/web/horizon healthy; scheduler running |
| Ingress routing (`/api/v1/health/*`, homepage) | **PASS** (release host) | 200 via nginx after W09-D3 fix |
| Playwright (Chromium desktop+mobile, EN+AR) | **PASS** (release host) | functional + RTL + mobile enforced; visual pixels not gated (see note) |
| axe accessibility | **PASS** (release host) | `e2e/a11y.spec.ts` |
| Ops: correlation-ID / readiness-degradation / recovery | **PASS** (release host) | X-Correlation-ID echoed; `/ready` 503 with Redis down, `/live` 200, recovers |

### Caveat — Pest `--parallel`
The parallel runner reports ~11 spurious failures from cross-worker DB isolation; the
authoritative **sequential** run is green. Run Pest sequentially in CI (or
give each worker an isolated database) until parallel isolation is hardened.

### Note — visual regression is not gated by this UAT
The `e2e/visual/*` pixel-snapshot specs require a deterministic, demo-seeded backend and
per-OS baselines (they self-document this). The RC production stack is intentionally not a
demo-seeded environment, so the UAT runs Playwright with `--ignore-snapshots`: every
functional, a11y, RTL, and mobile assertion is enforced, but per-OS pixel diffs are owned by
the dedicated seeded CI environment, not this cross-machine deployment UAT.

## Defects found & fixed (release-blocking)
See `UAT_MATRIX.md` for the full table.
- **W09-D1 (HIGH)** — web `/api/v1/v1/...` double-prefix 404'd the authoring/media/grading/
  player surface. Fixed across 7 files; guard: `apps/web/tests/contract/no-double-v1-prefix.test.ts`.
- **W09-D2 (HIGH)** — checkout double-charge on duplicate submit. Fixed with a per-user
  distributed lock; guards in `tests/Feature/Commerce/CartCheckoutTest.php`.
- **W09-D3 (CRITICAL)** — nginx fronting php-fpm used `SCRIPT_FILENAME $realpath_root$fastcgi_script_name`,
  but nginx runs in a separate container without the Laravel files, so `$realpath_root` was empty and
  **every `/api/*` request 404'd — the entire API was unreachable through the production ingress**.
  Fixed by hardcoding the php-fpm document root (`/var/www/html/public`) in `infra/nginx/nginx.conf`.
  Verified: `/api/v1/health/{live,ready}` and `/api/v1/health` all return 200 through nginx in the
  full local UAT.

## Reproduce the runnable gates
```
# Backend (apps/api) — requires PostgreSQL + Redis
php artisan migrate:fresh --seed --force
vendor/bin/pest                 # sequential; 823 passed
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
vendor/bin/deptrac analyse --no-progress

# Frontend (apps/web)
npm run typecheck && npm run lint && npx vitest run && npm run build

# Backup/restore drill
DATABASE_URL=postgres://user:pass@host:5432/db ./scripts/db-backup.sh ./backups
DATABASE_URL=postgres://user:pass@host:5432/scratch ./scripts/db-restore.sh <dump> --force
```

## Local-only gates
Everything requiring Docker or a browser is orchestrated by **`W09_WINDOWS_UAT.ps1`**
in this directory: it builds the images, starts the stack, runs health + backend + frontend
gates + Playwright + axe + Trivy + the backup/restore drill, writes all evidence into
`docs/verification/w09/evidence/<yyyyMMdd-HHmmss>/` (`summary.txt`, `summary.json`,
`failures.txt`, per-gate logs, `container-logs/`, `playwright-results/`, `axe-results/`),
and tears the stack down. Evidence files carry variable NAMES and PASS/FAIL only, never secret
values. Run it on the Docker host, then attach `summary.txt` + `failures.txt`.
