# HElbaron LMS — Deployment Checklist

Client handoff / first production deploy. Written against commit `c9acd4c`.

Two deployables: **`apps/api`** (Laravel 12 / PHP 8.3, served by FrankenPHP-Octane or php-fpm) and
**`apps/web`** (Next.js 15, Node 20+). They talk over HTTP; the browser only ever talks to the web app.

---

## 0. Before you start — the four settings that will bite you

These have defaults that are *safe for local dev and wrong for production*. Three of the four are
silent — only the first is caught by a guard.

| Variable | Default if unset | Symptom in production | Caught at boot? |
|---|---|---|---|
| `MEDIA_INGESTION_PROVIDER` | `fake` | Uploads appear to succeed and store no bytes; course/trainer images never render. Set to `s3` (or `local`). | **Yes** — `ProductionConfigValidator::criticalErrors()` rejects it and `AppServiceProvider` refuses to serve traffic. |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:3000` | Any browser call made directly to the API is refused. Set to your web origin. | No |
| `SANCTUM_STATEFUL_DOMAINS` | localhost list | Cookie/session auth rejected for your real domain. | No |
| `FILESYSTEM_DISK` | `local` | Files land on the app server's disk instead of object storage; lost on redeploy. | No |

The boot guard only fires when `APP_ENV=production`. A staging environment running `APP_ENV=staging`
gets **no** protection — there, `MEDIA_INGESTION_PROVIDER=fake` fails exactly as silently as the
other three. Run `php artisan config:validate --strict` on every environment rather than relying on
the environment name.

`apps/api/.env.production.example` does **not** list these four (it carries a `MEDIA_PROVIDER` key
that nothing reads). Add them by hand — see §2.

---

## 1. Fresh server clone

```bash
git clone <repo-url> helbaron && cd helbaron
git checkout <release-tag-or-commit>
```

Required: PHP 8.3+ (`bcmath ctype curl dom fileinfo gd intl mbstring openssl pdo_pgsql redis zip`),
Composer 2, Node 20+, PostgreSQL 15+, Redis 7+.

> `gd` is genuinely required — image variant generation uses it. The dev container ships without it,
> which is why variants are queued rather than inline locally.

## 2. Environment

```bash
cp apps/api/.env.production.example apps/api/.env
cp apps/web/.env.production.example apps/web/.env.production
php -d detect_unicode=0 apps/api/artisan key:generate --force   # or set APP_KEY by hand
```

**API — fill in `apps/api/.env`:**

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com
APP_TRUSTED_HOSTS=api.example.com          # empty = no host allow-list
TRUSTED_PROXIES=*                          # narrow to your LB CIDR if you can

DB_CONNECTION=pgsql
DB_HOST=... DB_PORT=5432 DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=...
REDIS_HOST=... REDIS_PORT=6379 REDIS_PASSWORD=...

CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_DOMAIN=.example.com
QUEUE_CONNECTION=redis

# --- NOT in the template; must be added (see §0) ---
FILESYSTEM_DISK=s3
MEDIA_INGESTION_PROVIDER=s3               # fake = stores nothing
MEDIA_S3_DISK=s3
MEDIA_IMAGE_DISK=s3                       # variants must sit beside the original
MEDIA_PUBLIC_BASE_URL=https://cdn.example.com   # omit to serve public media off APP_URL
MEDIA_PUBLIC_PATH_PREFIX=media/public
AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=... AWS_DEFAULT_REGION=... AWS_BUCKET=...
CORS_ALLOWED_ORIGINS=https://www.example.com
SANCTUM_STATEFUL_DOMAINS=www.example.com
FRONTEND_URL=https://www.example.com
# ---------------------------------------------------

MAIL_MAILER=smtp
MAIL_HOST=... MAIL_PORT=587 MAIL_USERNAME=... MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="HElbaron"

COMMERCE_PAYMENT_PROVIDER=aps             # `fake` is the default — never ship it
COMMERCE_ALLOW_FAKE_GATEWAY=false
COMMERCE_WEBHOOK_SECRET=...
APS_MERCHANT_IDENTIFIER=... APS_ACCESS_CODE=... APS_REQUEST_PHRASE=... APS_RESPONSE_PHRASE=...
APS_RETURN_URL=https://www.example.com/checkout/return

SECURITY_HSTS_ENABLED=true
ADMIN_REQUIRE_MFA=true
AI_ENABLED=false                          # set true only with a provider key configured
AI_ALLOW_FAKE=false
LOG_CHANNEL=stack
LOG_LEVEL=warning
SENTRY_LARAVEL_DSN=...                    # optional
```

**Web — fill in `apps/web/.env.production`:**

```dotenv
NODE_ENV=production
NEXT_PUBLIC_SITE_URL=https://www.example.com
NEXT_PUBLIC_API_BASE_URL=https://api.example.com/api/v1   # browser-visible
API_INTERNAL_URL=http://api.internal:8000/api/v1          # server-to-server; may stay private
```

`API_INTERNAL_URL` is what the BFF (`/api/session`, `/api/backend/*`) calls server-side. If the API
is not reachable on a private address, set it to the same value as `NEXT_PUBLIC_API_BASE_URL`.

Verify nothing secret leaked into the client bundle: only `NEXT_PUBLIC_*` is inlined.

## 3. Install & build

```bash
# API
cd apps/api
composer install --no-dev --optimize-autoloader --no-interaction

# Web
cd ../web
npm ci
npm run build
```

## 4. Database

```bash
cd apps/api
php artisan migrate --force
```

**Seed only what production needs.** `db:seed` runs the full demo catalog — do NOT run it on a
client environment. Seed the structural data individually:

```bash
php artisan db:seed --force --class="App\Platform\Identity\Database\Seeders\RolePermissionSeeder"
php artisan db:seed --force --class="Database\Seeders\StaffRoleTemplatesSeeder"
php artisan db:seed --force --class="App\Platform\Navigation\Database\Seeders\NavigationSeeder"
php artisan db:seed --force --class="App\Platform\Branding\Database\Seeders\BrandingSeeder"
php artisan db:seed --force --class="App\Platform\Pages\Database\Seeders\StaticPagesSeeder"
php artisan db:seed --force --class="App\Platform\Features\Database\Seeders\FeatureFlagsSeeder"
php artisan db:seed --force --class="App\Platform\Homepage\Database\Seeders\HomepageSeeder"
```

All are idempotent. Re-run `NavigationSeeder` after any release that adds a sidebar entry — the
frontend's `nav.ts` is only a fallback; `AppShell` renders the CMS menu when one exists.

## 5. Storage & caches

```bash
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache
```

Re-run the `*:cache` commands on every deploy, after the new code is in place.

## 6. Queue worker + scheduler

Both are required. Media variant generation, notification delivery, payment retries, subscription
renewals and Q&A reminders all run off them.

```bash
# systemd unit — queue worker (run 1+; use --queue to prioritise if needed)
php artisan queue:work redis --sleep=1 --tries=3 --max-time=3600

# crontab — scheduler
* * * * * cd /srv/helbaron/apps/api && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled jobs use `withoutOverlapping()->onOneServer()`, so the cron may safely exist on every node.

## 7. Web process

```bash
cd apps/web && npm run start      # or `node .next/standalone/server.js` if standalone output is enabled
```

Put both behind TLS. The web app must be able to reach `API_INTERNAL_URL`.

## 8. Health checks

```bash
curl -fsS https://api.example.com/api/v1/health/live    # process is up
curl -fsS https://api.example.com/api/v1/health/ready   # DB + Redis reachable
curl -fsS https://www.example.com/api/health            # web BFF
```

Point the load balancer at `/api/v1/health/ready` for the API and `/api/health` for the web app.

## 9. Post-deploy smoke test

1. `/` and `/courses` render, images load (this is the `MEDIA_INGESTION_PROVIDER` canary).
2. Log in as an admin → Filament `/admin` → upload a course thumbnail → it appears on the card.
   If it does not, `MEDIA_INGESTION_PROVIDER` is still `fake`.
3. `/trainers` shows avatars; a course detail page plays its trailer.
4. Instructor sidebar shows **Questions**; open a learner question and reply.
5. Manager `/manager/seats` and `/manager/training` agree on seat counts.
6. Toggle AR — `<html lang="ar" dir="rtl">`; check 390px for horizontal overflow.
7. Place a test order against the real gateway in its sandbox before going live.

---

## Known issues at handoff

| Issue | Impact | Action |
|---|---|---|
| `demo:seed` aborts at `seedMetrics` (`DemoSeeder.php:1772`) with SQLSTATE 42P10 | Demo dataset seeds ~90% then throws. Does not affect production (demo seeding is disabled unless `DEMO_MODE=true`). | See the note below — **do not** simply add a unique index. |
| `apps/api/storage/app/private/manual-import/` is gitignored | Operator-staged source images (course covers + trainer portraits of real people). It is the only copy that records *which* portrait belongs to whom once imported bytes are stored under UUID filenames. **Not covered by a DB dump.** | Back it up with the database. See `LOCAL_QA_RUNBOOK.md`. |
| Two courses have no uploaded image: **Business Development Essentials**, **Essential Business Skills** | They render the generated CourseCover fallback — intentional, not broken. | Upload via Admin → Catalog → Courses → Edit → Thumbnail, or run `php artisan catalog:report-missing-public-media` for the live list. |
| `.env.production.example` omits `MEDIA_INGESTION_PROVIDER`, `CORS_ALLOWED_ORIGINS`, `SANCTUM_STATEFUL_DOMAINS`, `FILESYSTEM_DISK`; carries an unused `MEDIA_PROVIDER` key | Silent misconfiguration — see §0. | Use §2 as the authoritative list. |
| PHPStan baseline drift | **282 errors** on top of the 684-entry `phpstan-baseline.neon`. Pre-existing, spread across untouched files. `deptrac` is clean (0 violations) and `pint --test` is clean on all shipped code. Not a deploy blocker. | Burn down separately; keep new code clean (it is). |
| `pint --test` reports one file | `apps/api/qa_role_map.php` — gitignored, never shipped. Deliberately left unformatted. | None. |
| `gd` required for image variants | Without it the variant job fails and retries. Originals still serve. | Ensure `php-gd` is installed on the app/worker image. |

### `demo:seed` / `metric_snapshots` — actual root cause

The unique index **does** exist. Migration `2026_08_08_000100_add_organization_id_to_metric_snapshots_table`
(the T1 tenancy change) dropped the plain `metric_snapshots_unique` and replaced it with two
*partial* indexes:

```sql
CREATE UNIQUE INDEX metric_snapshots_unique_global ON metric_snapshots
  (metric_key, granularity, period, dimension_key, dimension_value) WHERE organization_id IS NULL;
CREATE UNIQUE INDEX metric_snapshots_unique_org ON metric_snapshots
  (organization_id, metric_key, granularity, period, dimension_key, dimension_value) WHERE organization_id IS NOT NULL;
```

Postgres will not infer a conflict target from a *partial* index unless the statement repeats the
index predicate. Laravel's `upsert()` emits a bare `ON CONFLICT (cols)` with no `WHERE`, so Postgres
raises `42P10: there is no unique or exclusion constraint matching the ON CONFLICT specification`.

**Do not add a non-partial unique index over the five columns.** It would be redundant with
`metric_snapshots_unique_global` and would merge every tenant's metrics back into a single bucket,
undoing the isolation T1 introduced. The fix belongs in the seeder — replace the `upsert()` with a
raw statement carrying the predicate (`... ON CONFLICT (metric_key, granularity, period,
dimension_key, dimension_value) WHERE organization_id IS NULL DO UPDATE ...`), since `DemoSeeder`
only ever writes global rows.

### Running the gates

**Run every gate inside the API container.** `apps/api` is bind-mounted to `/var/www/html`, so the
container always sees the working tree — there is no staleness risk, and the container is the only
environment whose numbers can be trusted:

```bash
docker compose exec api php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
docker compose exec api php vendor/bin/pint --test
docker compose exec api php vendor/bin/deptrac analyse --no-progress
```

A bare `pint --test` invoked from the Windows host **silently under-reports**: it walks only the
top-level directory and never recurses into `app/`, so it found 1 file where the container found 7.
Passing explicit paths on the host reproduces the container's result, but the bare form does not —
do not use it as a gate. PHPStan gives an identical 282 in both environments; it does **not** depend
on a live database.

The full test suite runs in a single invocation:

```bash
docker compose exec -T api php artisan test --parallel --processes=6
```

Verified green at `a3e6ce9`: **1919 passed, 28 skipped (6351 assertions)**, 6 processes, 949s.

Two things to know when it misbehaves:

- **Duplicate global helpers are fatal.** Pest test files declare helpers at global scope, so two
  files declaring the same name kill the whole run with `Cannot redeclare ...` before any test
  executes. The convention here is `if (! function_exists('name')) { ... }`, which eight duplicated
  names use — but that guard is only safe when both declarations are interchangeable. When the
  signatures differ, rename instead, or the second file silently gets the first file's
  implementation. To audit:

  ```bash
  grep -rnE "^[[:space:]]*function [a-zA-Z_][a-zA-Z0-9_]*[[:space:]]*\(" tests/ \
    | grep -vE "public |private |protected " \
    | sed -E 's|^([^:]+):([0-9]+):[[:space:]]*function ([a-zA-Z_][a-zA-Z0-9_]*).*|\3\t\1:\2|' \
    | sort | awk -F'\t' '{n[$1]++} END {for (f in n) if (n[f]>1) print f}'
  ```

- **Stale parallel test databases cause phantom failures.** An aborted run leaves
  `helbaron_test_test_*` behind, and a later run reusing them can fail with unrelated
  `QueryException`s (this produced four spurious Analytics failures once, which then passed 84/84 on
  two clean re-runs). Add `--recreate-databases` when a run fails in a way that looks like
  contention.

## Backups to take together

A database dump alone is **not** a complete backup — uploaded media lives on disk/object storage.

```bash
pg_dump -U <user> -d <db> --clean --if-exists | gzip > backups/db-$(date +%Y%m%d-%H%M%S).sql.gz
```

Also back up (or confirm they live in durable object storage):

- `apps/api/storage/app/public/media/` — uploaded course/trainer images and derived variants
- `apps/api/storage/app/private/manual-import/` — labelled operator source images (gitignored)
- `apps/api/storage/app/private/certificates/` — issued certificate artefacts
- `apps/api/storage/app/private/exports/` — generated report exports
