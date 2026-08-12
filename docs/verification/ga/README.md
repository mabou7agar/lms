# GA Acceptance Runner -- v1.0.0-rc.2

One PowerShell script automates the remaining GA acceptance evidence for release
candidate `v1.0.0-rc.2` and produces a single GO / NO-GO decision with an evidence
directory. It is **verification-only by default**: it never creates or moves a tag
unless you explicitly pass `-PromoteToGA` and every mandatory gate is `PASS`.

This runner only *automates* the checks. It asserts no result of its own; every
`PASS`/`FAIL` is derived from actual command exit codes and captured output.

## Run it

```
powershell -NoProfile -ExecutionPolicy Bypass -File ".\docs\verification\ga\GA_ACCEPTANCE_v1.0.0-rc.2.ps1"
```

Optional promotion (only tags/pushes `v1.0.0` when the decision is GO on the exact
tested commit):

```
powershell -NoProfile -ExecutionPolicy Bypass -File ".\docs\verification\ga\GA_ACCEPTANCE_v1.0.0-rc.2.ps1" -PromoteToGA
```

Compatible with Windows PowerShell 5.1 and PowerShell 7. The script body is ASCII
and the file is saved with a UTF-8 BOM.

## Prerequisites (operator responsibilities)

1. **Docker Desktop must be running** (the production stack is built and started from
   `docker-compose.prod.yml`).
2. **`git`, `docker`, `curl.exe`, `npm`** on PATH. `trivy` is required for the image
   scan; if absent the security check is recorded as `FAIL`, never a fake `PASS`.
   `gitleaks`/`composer` are used if present, otherwise CI runs the authoritative pass.
3. **Provider credentials** may be supplied via your `.env.production` / environment.
   Missing credentials are recorded as `EXTERNAL_CREDENTIAL_REQUIRED` and only cause a
   `NO-GO` when the provider is marked DAY-1 required.

## DAY-1 provider requirements (override with env vars)

```
GA_REQUIRE_PAYMENT=1   GA_REQUIRE_MEDIA=1   GA_REQUIRE_MAIL=1
GA_REQUIRE_SMS=0       GA_REQUIRE_SSO=0     GA_REQUIRE_AI=0
```

A missing **required** provider credential produces `NO-GO -- EXTERNAL VALIDATION
REQUIRED`. Optional providers do not block. Semantic search uses the portable
JSONB/cosine driver by design; pgvector is not required.

## Switches

- `-PromoteToGA` -- create + push annotated `v1.0.0` only on a clean GO at the tested commit.
- `-KeepStackUp` -- leave the Docker stack running after the run (default: `compose down`).
- `-AllowExistingGaTag` -- verification-only tolerance if `v1.0.0` already exists; never overwrites.

## The 10 mandatory checks

1. Production containers built from scratch + health/readiness through ingress.
2. Trivy image scan (HIGH/CRITICAL) + secret scan + composer/npm dependency audit.
3. Playwright browser suite against the running stack.
4. axe accessibility (0 serious / 0 critical) + `build-storybook` (no visual-regression service -> `NOT_APPLICABLE`).
5. Backup/restore drill via the postgres container (DB only; `OBJECT_STORAGE_INCLUDED=NO`).
6. `php artisan config:validate --strict` in the api container.
7. Queue + scheduler runtime smoke (`schedule:run`, `horizon:status`, `queue:failed`).
8. Live API contract: fetch `/api/openapi.json`, assert no `/api/v1/v1` route.
9. DAY-1 provider credential resolution and smoke where credentials exist.
10. Fresh full gates: `migrate:fresh --seed`, Pest (serial), Pint, PHPStan, Deptrac,
    `composer audit`; frontend typecheck, lint (`--max-warnings=0`), vitest, build, storybook.

## Evidence

Each run writes to:

```
docs\verification\ga\evidence\<yyyyMMdd-HHmmss>\
```

containing `summary.txt`, `summary.json`, `failures.txt`, per-check logs
(`docker-build.txt`, `trivy-*.txt`, `playwright.txt`, `axe.txt`, `backup.txt`,
`restore.txt`, `backend-gates.txt`, `frontend-gates.txt`, `ga-decision.txt`, ...) and
`container-logs\`. No secret values are written to any evidence file.

## Safety

- Verifies `v1.0.0-rc.2` resolves to `04e47ec5d4162338695ad12838ee04aded76cd0b` (the frozen
  tested product commit) and that `HEAD` differs from rc.2 only by GA tooling under
  `docs/verification/`; any other tracked product change aborts (a new RC is required).
  On promotion the `v1.0.0` tag is created at the acceptance-tested `HEAD`.
- Aborts on tracked working-tree changes (tolerates only the two historical untracked
  docs paths).
- Never force-pushes, never moves or deletes `v1.0.0-rc.2`.
- Cleans up the temporary restore database, any disposable env file, and (unless
  `-KeepStackUp`) the stack, in a `finally` block.

## Decision

`GO` requires all ten mandatory checks `PASS` (required providers included). Any
mandatory `FAIL`, or a missing DAY-1 provider, yields `NO-GO`. A failed
security/backup/test gate cannot be overridden by any flag.
