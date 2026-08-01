# W09 — Release Candidate Verification (1.0.0-rc.1)

Concise evidence index for the RC gate. Runnable gates were executed in the cloud
sandbox at the RC code; Docker/browser gates are consolidated into
`W09_WINDOWS_UAT.ps1` for the release host.

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
| Config validation (`config:validate --strict`) | **PASS** (W08, on-machine) | rejects placeholder config as designed |
| Backup / restore drill | **PASS** | 520K dump, integrity + SHA-256 OK, 148 = 148 restored |
| API image build + Trivy | **PASS** (W08, on-machine) | build OK; 0 vulnerabilities |
| Web image build + Trivy | **PASS** (W08, on-machine) | build OK; 0 HIGH/CRITICAL after postcss/sharp bump |
| CI workflow YAML validation | **PASS** (W08) | ci/deploy/adr/uptime parse clean |
| Container health smoke | **LOCAL REQUIRED** | no Docker daemon in sandbox — see PS1 |
| Playwright (Chromium desktop+mobile, EN+AR) | **LOCAL REQUIRED** | e2e wired; needs running stack — see PS1 |
| axe accessibility | **LOCAL REQUIRED** | `e2e/a11y.spec.ts` — see PS1 |

### Caveat — Pest `--parallel`
The parallel runner reports ~11 spurious failures from cross-worker DB isolation; the
authoritative **sequential** run is green (823 passed). Run Pest sequentially in CI (or
give each worker an isolated database) until parallel isolation is hardened.

## Defects found & fixed (release-blocking)
See `UAT_MATRIX.md` for the full table.
- **W09-D1 (HIGH)** — web `/api/v1/v1/...` double-prefix 404'd the authoring/media/grading/
  player surface. Fixed across 7 files; guard: `apps/web/tests/contract/no-double-v1-prefix.test.ts`.
- **W09-D2 (HIGH)** — checkout double-charge on duplicate submit. Fixed with a per-user
  distributed lock; guards in `tests/Feature/Commerce/CartCheckoutTest.php`.

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
