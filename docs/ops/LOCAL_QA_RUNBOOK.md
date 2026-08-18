# HElbaron — Local QA Runbook

How to bring the stack up for manual/browser QA, and what "correct" looks like at each step.

## Runtimes: which one you are on matters

| Piece | Intended | Fallback |
|---|---|---|
| Postgres | `helbaron-postgres` container, host port **55432** | — |
| Redis | `helbaron-redis` container, host port **6380** | — |
| API | `helbaron-api` container: **Octane + FrankenPHP, 20 workers**, port 8000 | `php artisan serve` on the host |
| Web | `next dev` (QA) or standalone build (release check) | — |

The API fallback is not equivalent. `php artisan serve` is PHP's built-in server: single-threaded,
and `PHP_CLI_SERVER_WORKERS` needs `pcntl`, which does not exist on Windows. Every request is served
strictly one after another. Measured on this repo: **8 concurrent API calls → 1.4s, 2.8s, 4.0s …
10.1s**, and **6 concurrent `/courses` page loads → 13.5s–16.8s**. A page that loads a dozen
thumbnails plus a few API calls will look hung. Use the container for anything timing-related.

## Start (intended path)

```bash
docker compose up -d              # postgres, redis, api (Octane)
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8000/api/v1/health   # expect 200

cd apps/web && npm run dev -- --port 3002                                       # http://localhost:3002
```

After editing PHP code, Octane keeps the old code in memory:

```bash
docker compose exec api php artisan octane:reload
```

## Start (host fallback — only when Docker cannot mount the repo)

```bash
cd apps/api
DB_HOST=127.0.0.1 DB_PORT=55432 REDIS_HOST=127.0.0.1 REDIS_PORT=6380 \
  php artisan serve --host=127.0.0.1 --port=8000
```

Accept that concurrency is serialised. Do not report page-speed or hang defects from this mode.

### Known Docker Desktop fault

`Error response from daemon: error while creating mount source path
'/run/desktop/mnt/host/d/...': mkdir /run/desktop/mnt/host/d: file exists`

Every `D:` bind mount fails, not just this project's. It is a Docker Desktop / WSL mount-point
staleness fault, not a repo problem. Confirm with:

```bash
docker run --rm -v "D:/:/t" alpine ls /t
```

Recovery is `wsl --shutdown` followed by a Docker Desktop restart — which also bounces every other
running container on the machine.

## Web talks to the API through the BFF, not directly

`apps/web/.env.local`:

```
NEXT_PUBLIC_API_BASE_URL=http://127.0.0.1:8000/api/v1   # SSR + absolute links
API_INTERNAL_URL=http://127.0.0.1:8000/api/v1           # the /api/backend proxy
```

Browser calls go to same-origin `/api/backend/*`; the proxy attaches the httpOnly session token
server-side. A browser request straight to port 8000 is cross-origin and will not carry the session.

### Both URLs must be reachable *from the web server process*

`NEXT_PUBLIC_API_BASE_URL` is baked into the build and is also used for server-side fetches. If it
points at an address the Next.js process cannot open, nothing errors loudly — each page just pays a
TCP connect timeout and then renders its empty-data fallback. Measured on the standalone build with
the API bound to loopback only while the web app was told `http://172.19.240.1:8000`:

| | `/` | `/courses` | `/bundles` | `/pricing` |
|---|---|---|---|---|
| API unreachable from SSR | 4.15s | 4.12s | 4.13s | 4.13s |
| API reachable | 3.39s | 3.89s | 2.13s | 2.13s |

The tell is the *uniformity*: four different pages doing four different amounts of work cannot all
take 4.1s. A flat row like that is a timeout, not load. Serve the API on the same host the web app
was built to call (`php artisan serve --host=0.0.0.0`) rather than raising timeouts.

## Release check: the standalone build

```bash
cd apps/web
rm -rf .next && npm run build
cp -r public .next/standalone/ && cp -r .next/static .next/standalone/.next/
PORT=3003 HOSTNAME=0.0.0.0 npm run start:standalone
```

`.next/standalone/server.js` is written during "Collecting build traces", after the route table has
already printed — the directory holding only `node_modules` and `package.json` means the build is
still running, not that it failed.

Public pages are client-fetched, so `curl` returns a shell with no prices or nav labels in it. Assert
content in a browser; use `curl` only for status codes and timings.

## Smoke checks

```bash
# API reachable and the three public reads answer
for p in health courses products homepage; do
  curl -s -o /dev/null -w "$p %{http_code} %{time_total}s\n" http://127.0.0.1:8000/api/v1/$p
done

# Public pages render
for p in / /courses /bundles /pricing; do
  curl -s -o /dev/null -w "$p %{http_code} %{time_total}s\n" http://localhost:3002$p
done
```

Expected: every line `200`. On the container runtime each page is well under a second warm.

## Commerce truth checks

There are no free courses: **every published course must be purchasable**.

```bash
curl -s "http://127.0.0.1:8000/api/v1/courses?per_page=50" | \
  python -c "import sys,json; d=json.load(sys.stdin); \
  bad=[c['title'] for c in d['data'] if not (c.get('purchase') or {}).get('purchasable')]; \
  print('unpurchasable:', len(bad), bad)"
```

Expected `unpurchasable: 0`. If not, run the seeder — it is idempotent and never overwrites a price
an admin has changed:

```bash
cd apps/api && php artisan db:seed \
  --class="App\Contexts\Commerce\Database\Seeders\CommerceSeeder" --force
```

No course may be sold by two active course-products: that makes the card, the detail page and the
cart able to quote different prices.

```bash
cd apps/api && php artisan tinker --execute="
use Illuminate\Support\Facades\DB;
echo DB::table('product_courses')->join('products','products.id','=','product_courses.product_id')
  ->where('products.status','active')->where('products.type','course')->whereNull('products.deleted_at')
  ->select('product_courses.course_id')->groupBy('product_courses.course_id')
  ->havingRaw('count(*) > 1')->get()->count().' duplicated courses'.PHP_EOL;"
```

## Test accounts

| Role | Email | Password |
|---|---|---|
| Admin / super_admin | `admin@helbaron.local` | `password` |
| Instructor | `trainer@helbaron.local` | `password` |
| Company owner | `hotfix.company3@smoke.test` | `password123` |
| Employee | `emp.one@hotfix3.test` | `password123` |

## Gates

```bash
cd apps/web && npx tsc --noEmit && npx next lint && npx vitest run
cd apps/api && php vendor/bin/pint --test app && php vendor/bin/deptrac analyse --no-progress
cd apps/api && php -d memory_limit=3G vendor/bin/phpstan analyse --no-progress
```

Pest cannot run the whole `tests/Feature` tree in one process — two suites declare a global `enrol()`
helper and collide. Run per directory:

```bash
cd apps/api && DB_HOST=127.0.0.1 DB_PORT=55432 REDIS_HOST=127.0.0.1 REDIS_PORT=6380 \
  php vendor/bin/pest tests/Feature/Commerce --compact
```

Never run two Pest processes at once: they share `helbaron_test` and will fail each other's
`RefreshDatabase` in ways that look like real defects.
