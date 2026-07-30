# W07 — Local Windows Verification Guide

This guide lists the **exact PowerShell commands** to reproduce, on your Windows machine, the
verification that cannot run in the cloud sandbox (real Laravel API + Postgres/Redis services,
authenticated browser journeys, and machine-level checks). Everything else (all 9 repository gates,
plus Playwright E2E + axe accessibility against the public surfaces) already ran green in the
sandbox and does not need your machine.

> Placeholders you must replace are written like `<REPLACE_ME>`. Everything else is copy‑paste ready
> for this repository. Run each block from the **stated working directory**.

Repo root (this machine): `D:\Claude_Files\Projects\LMS\CoreLMS Implementation\corelms`
- Backend (Laravel 12): `.\apps\api`
- Frontend (Next.js 15): `.\apps\web`

---

## 0. Prerequisites

Install (or confirm) these once. Versions below match what the code is built against.

- PHP **8.4** with extensions: `pdo_pgsql`, `redis` (or `predis` is bundled), `mbstring`, `intl`, `bcmath`, `gd`, `zip`.
- Composer 2.x
- Node.js **22.x** + npm
- PostgreSQL **16** (local service or Docker)
- Redis **7** (local service or Docker) — the app is configured for **port 6380** (see `apps\api\.env`: `REDIS_PORT=6380`).
- Git

Check versions (working dir: repo root):

```powershell
php -v ; composer --version ; node -v ; npm -v
```

Expected: PHP 8.4.x, Composer 2.x, Node v22.x.

---

## 1. Start the data services (Docker — recommended)

Working directory: **repo root** `corelms` (the `docker-compose.yml` lives here, NOT in `apps\api`).
It maps Postgres to host port **55432** and Redis to **6380** (container `helbaron-postgres` /
`helbaron-redis`), matching `apps\api\.env`.

```powershell
cd "D:\Claude_Files\Projects\LMS\CoreLMS Implementation\corelms"
docker compose up -d
```

If you do NOT use the repo compose file, start equivalents (Postgres 16 on host **55432**, Redis on 6380):

```powershell
docker run -d --name helbaron-postgres -e POSTGRES_DB=helbaron -e POSTGRES_USER=helbaron -e POSTGRES_PASSWORD=secret -p 55432:5432 postgres:16-alpine
docker run -d --name helbaron-redis -p 6380:6379 redis:7-alpine
```

Health check:

```powershell
docker ps
# Postgres reachable (host port 55432):
docker exec helbaron-postgres pg_isready -U helbaron -d helbaron
# Redis reachable (note the 6380 host port):
docker exec helbaron-redis redis-cli ping
```

Expected: `pg_isready` → `accepting connections`; `redis-cli ping` → `PONG`.

**Common failure:** `Ports are not available: 55432/6380 already in use` → stop the conflicting local
service, or change the host port and update `apps\api\.env` (`DB_PORT` / `REDIS_PORT`) to match.

**The failure this guide was written to prevent:** running `php artisan test` (or `migrate:fresh`)
without these services up produces `SQLSTATE[08006] ... port 55432 ... Connection refused` on every
DB-touching test. That is an environment error, not a code failure — bring the services up first.

---

## 2. Backend: env, install, migrate + seed

Working directory: `apps\api`

```powershell
cd "D:\Claude_Files\Projects\LMS\CoreLMS Implementation\corelms\apps\api"
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
composer install
php artisan key:generate
```

Required `.env` values (edit `apps\api\.env`; replace placeholders):

```
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=55432
DB_DATABASE=helbaron
DB_USERNAME=helbaron
DB_PASSWORD=secret
REDIS_HOST=127.0.0.1
REDIS_PORT=6380
CACHE_STORE=redis
QUEUE_CONNECTION=redis
# Commerce fake gateway (local only):
COMMERCE_PAYMENT_PROVIDER=fake
COMMERCE_PAYMENT_WEBHOOK_SECRET=<REPLACE_ANY_LOCAL_SECRET>
```

Clean database migrate + seed (this is Gate 1):

```powershell
php artisan migrate:fresh --seed
```

Expected: every migration `DONE`, then every seeder `DONE` (ending with `SeoSeeder ... DONE`). Exit code 0.

**Common failure:** `SQLSTATE[08006] connection refused` → services from step 1 aren't up, or
`.env` DB/Redis host/port is wrong. `FOR UPDATE is not allowed with aggregate functions` should NOT
appear — that Postgres-only dunning bug was fixed in W07.

---

## 3. Backend: the 5 repository gates (PowerShell)

Working directory: `apps\api`. Run each; all must exit 0.

```powershell
# Gate 2 — unit/feature tests (Postgres-backed):
php artisan test

# Gate 3 — static analysis:
php -d memory_limit=-1 vendor\bin\phpstan analyse --no-progress

# Gate 4 — architecture boundaries:
php vendor\bin\deptrac analyse --no-progress

# Gate 5 — code style:
vendor\bin\pint --test
```

Expected:
- `php artisan test` → `Tests: 808 passed` (0 failed).
- PHPStan → `[OK] No errors`.
- Deptrac → `Violations 0 / Errors 0`.
- Pint → `{"tool":"pint","result":"passed"}`.

**Common failure (DB):** a `SQLSTATE[08006] ... Connection refused` on many/most tests means
Postgres/Redis aren't reachable — do Step 1 first. `phpunit.xml` sets only `APP_ENV=testing` and
does not override the DB host/port, so tests use `apps\api\.env` (`DB_PORT=55432`). PHPStan (Gate 3)
and Deptrac (Gate 4) do NOT need the database and will pass even when it is down — a green PHPStan +
Deptrac alongside a wall of `08006` failures confirms the code is fine and only the DB is missing.

**Common failure (Mux/OpenSSL):** `Tests\Feature\Integrations\MuxPlaybackTest ... openssl_pkey_export():
Cannot get key from parameter 1` is a local PHP OpenSSL config issue, not a code defect —
`openssl_pkey_new()` returned false. Fix your PHP install: enable `extension=openssl` in `php.ini` and
set the `OPENSSL_CONF` environment variable to a valid `openssl.cnf` (ships with most PHP/OpenSSL
distributions), then re-run.

---

## 4. Backend: run the API, queue worker, scheduler

Open **three** PowerShell windows, all in `apps\api`.

```powershell
# Window A — HTTP API on :8000
php artisan serve --host=127.0.0.1 --port=8000

# Window B — queue worker (notifications, fan-out, fulfillment jobs)
php artisan queue:work --tries=3 --backoff=10,60,300

# Window C — scheduler (dunning retries + subscription renewals run hourly via onOneServer)
php artisan schedule:work
```

Health checks (new window, any dir):

```powershell
curl.exe http://127.0.0.1:8000/up
curl.exe http://127.0.0.1:8000/api/v1/health
curl.exe http://127.0.0.1:8000/api/v1/health/ready
```

Expected: `/up` returns HTTP 200; `/api/v1/health` returns a JSON envelope with `"status"`;
`/api/v1/health/ready` returns 200 when DB + Redis are reachable (503 otherwise).

To exercise the scheduled commands immediately (instead of waiting for the hour):

```powershell
php artisan commerce:retry-failed-payments
php artisan commerce:renew-subscriptions
```

Expected: both complete without error (no-ops when nothing is due).

---

## 5. Frontend: install, gates, run

Working directory: `apps\web`

```powershell
cd "D:\Claude_Files\Projects\LMS\CoreLMS Implementation\corelms\apps\web"
npm ci
```

The 4 frontend repository gates (all must exit 0):

```powershell
npm run typecheck   # Gate 6 — tsc --noEmit
npm run lint        # Gate 7 — eslint (0 errors; warnings are allowed)
npm run test -- --run   # Gate 8 — vitest
npm run build       # Gate 9 — next build
```

Expected:
- typecheck → no output, exit 0.
- lint → `✖ 9 problems (0 errors, 9 warnings)` — **0 errors** is the pass condition.
- vitest → `Test Files 98 passed`, `Tests 484 passed`.
- build → route table printed, ending with the `○ (Static) / ƒ (Dynamic)` legend, exit 0.

Run the app (points the SSR shell + BFF proxy at the local API from step 4):

```powershell
$env:NEXT_PUBLIC_API_BASE_URL="http://127.0.0.1:8000/api/v1"
$env:API_INTERNAL_URL="http://127.0.0.1:8000/api/v1"
npm run start   # serves the production build on http://localhost:3000
# (or: npm run dev  — for hot reload)
```

Health check: open `http://localhost:3000` — the marketing home should render.

---

## 6. Browser E2E — public surfaces (no backend needed)

Already GREEN in the sandbox, but to reproduce locally (working dir `apps\web`):

```powershell
npx playwright install chromium
npm run e2e -- --project=chromium e2e/smoke.spec.ts e2e/a11y.spec.ts
```

Expected: `5 passed, 2 skipped` (the 2 skipped are the authenticated legs — see step 7). The E2E
harness auto-starts a mock API + the Next app, so no real backend is required for these.

---

## 7. Browser E2E — AUTHENTICATED journeys (needs the real API from step 4)

This is the part that could not run in the sandbox (no seeded credentials + running API together).

1. Ensure the API (step 4, window A) and a Next server pointed at it are running.
2. Create/confirm a known test learner. Either use a seeded account or register one:

```powershell
# from apps\api — create a verified learner via tinker:
php artisan tinker
# then inside tinker:
#   $u = App\Platform\Identity\Models\User::factory()->create(['email'=>'e2e@local.test']);
#   $u->forceFill(['password'=>bcrypt('<REPLACE_E2E_PASSWORD>'),'email_verified_at'=>now()])->save();
#   exit
```

3. Run the authenticated smoke journey against the real stack (working dir `apps\web`):

```powershell
$env:PLAYWRIGHT_BASE_URL="http://localhost:3000"
$env:E2E_EMAIL="e2e@local.test"
$env:E2E_PASSWORD="<REPLACE_E2E_PASSWORD>"
npx playwright test --project=chromium e2e/smoke.spec.ts
```

Expected: `3 passed` including `authenticated journey: login -> dashboard -> logout`.

**Report back to me:** if any test fails, send the file
`apps\web\playwright-report\index.html` (or zip `apps\web\test-results\`) and paste the console
output of the command.

---

## 8. Accessibility (axe) — full run

Working directory: `apps\web`

```powershell
npm run e2e -- --project=chromium e2e/a11y.spec.ts
```

Expected: `3 passed, 1 skipped` (the skipped case is the authenticated dashboard shell — provide the
env vars from step 7 to include it). The suite runs axe-core (`wcag2a`/`wcag2aa`) on home, login and
pricing.

**Report back to me:** the printed axe summary lines, and `playwright-report\index.html` on failure.

---

## 9. Security spot-checks (curl against the running API)

Working directory: any. API from step 4 must be running. These reproduce W07 fixes.

```powershell
# (a) Unauthenticated protected endpoint → 401 JSON envelope, never a redirect/500:
curl.exe -i http://127.0.0.1:8000/api/v1/orders

# (b) Public coupon validation is rate-limited (commerce-coupon, 10/min). Fire 12 quickly:
1..12 | ForEach-Object { curl.exe -s -o NUL -w "%{http_code}`n" -X POST http://127.0.0.1:8000/api/v1/coupons/validate -H "Content-Type: application/json" -d '{"code":"NOPE"}' }

# (c) Fake media webhook must be ABSENT in production (present only in local/testing):
curl.exe -i -X POST http://127.0.0.1:8000/api/v1/media/webhooks/fake
```

Expected:
- (a) `HTTP/1.1 401` with body `{"error":{"code":"UNAUTHENTICATED",...}}`.
- (b) the first ~10 return `422`/`404` (validation), then `429` (Too Many Requests) once the limiter trips.
- (c) in `APP_ENV=local` returns 401/422 (route present); set `APP_ENV=production` and it must return **404** (route not registered).

---

## 10. What to send back to me

If anything above does not match "Expected", send me:
- The **exact command** you ran and its **full console output**.
- For test failures: `apps\api\storage\logs\laravel.log` (backend) and/or
  `apps\web\playwright-report\index.html` + `apps\web\test-results\` (browser).
- For migrate/DB issues: the output of `docker ps` and `docker logs helbaron-pg` / `helbaron-red`.
- For env issues: your `apps\api\.env` with secrets redacted.

I will diagnose from that output and, if a code fix is needed, apply it and re-sync.
