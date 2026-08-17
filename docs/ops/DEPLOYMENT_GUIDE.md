# HElbaron — Deployment Guide

## Artifacts
- One image (`apps/api/Dockerfile`) runs three roles: **web** (php-fpm behind nginx),
  **horizon** (queues), **scheduler** (`schedule:run` loop).
- Frontend (`apps/web`, Next.js 15) deploys separately (Vercel/Node/container).

### Running the frontend in production
`next.config.ts` sets `output: "standalone"`, so a production build emits a self-contained server at
`apps/web/.next/standalone/server.js`. Start it with that file — **not** `next start`, which cannot
serve a standalone build and exits with a warning:

```bash
cd apps/web
npm run build
npm run start:standalone        # === node .next/standalone/server.js
```

`apps/web/Dockerfile` already does this (`CMD ["node", "server.js"]`); the script exists for a
non-container host and for verifying a build locally. `npm run start` is kept because the Playwright
harness builds with `NEXT_DISABLE_STANDALONE=1` and needs `next start` to serve that build — it is
not the production entrypoint.

## Prerequisites
- PostgreSQL 16, Redis 7, S3 bucket + CloudFront distribution, TLS terminated upstream (ALB/CDN).
- A populated `apps/api/.env.production` (see `.env.example`; never commit real values).

## Build
```bash
docker build -t helbaron-api:1.0.0 -f apps/api/Dockerfile apps/api
# or via compose:
HELBARON_IMAGE=helbaron-api:1.0.0 docker compose -f docker-compose.prod.yml build
```

## Release (zero-downtime)
1. Build + push the immutable tag (`helbaron-api:<version>`).
2. Run migrations **once** from a one-off task (not per-replica):
   `php artisan migrate --force`
3. Roll web replicas (new image) behind the LB; readiness gate = `GET /api/v1/health/ready`.
4. Restart `horizon` (it terminates gracefully and drains) and `scheduler`.
5. Warm caches: `php artisan config:cache route:cache event:cache view:cache`.

## First-time bootstrap
```bash
php artisan key:generate --force        # if APP_KEY not provisioned via secrets
php artisan migrate --force
php artisan storage:link
php artisan db:seed --force             # optional: demo/reference data only
```

## Post-deploy verification
- `GET /up` → 200 ; `GET /api/v1/health/ready` → 200 with db+redis `ok:true`.
- `php artisan horizon:status` → running.
- Tail structured logs (`LOG_CHANNEL=json`) for the deploy correlation window.

## Automation
- Zero-downtime deploy: `./scripts/deploy.sh` (set `HELBARON_IMAGE`).
- Rollback: `./scripts/rollback.sh helbaron-api:<previous-tag>`.
- Pre-flight: `php artisan env:validate --production`.

## Rollback
See `ROLLBACK_GUIDE.md` — redeploy the previous image tag; migrations are expand/contract.
