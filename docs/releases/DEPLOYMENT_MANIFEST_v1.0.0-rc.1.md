# Deployment Manifest — 1.0.0-rc.1

Do **not** publish these images externally or push git during RC preparation. This
manifest describes what the deployment pipeline builds; the real commit SHA is injected
at build time (`APP_VERSION` / image tag) and recorded here after the RC tag is created.

## Images
Built from `docker-compose.prod.yml` at the RC commit. Tag each image with BOTH the
release version and the immutable commit SHA:

| Service | Image (version tag) | Immutable tag | Base |
|---|---|---|---|
| API (php-fpm) | `helbaron-api:1.0.0-rc.1` | `helbaron-api:sha-<SHORT_SHA>` | `php:8.3-fpm-alpine` |
| Web (Next.js) | `helbaron-web:1.0.0-rc.1` | `helbaron-web:sha-<SHORT_SHA>` | `node:22-alpine` |
| Nginx | `nginx:1.27-alpine` (pinned) | — | upstream |
| Postgres | `postgres:16-alpine` (pinned) | — | upstream |
| Redis | `redis:7-alpine` (pinned) | — | upstream |

The same API image runs three roles (php-fpm, `horizon`, `schedule:work`).

## Build + tag (on a Docker host)
```
docker compose --env-file apps/api/.env.production -f docker-compose.prod.yml build
SHORT_SHA=$(git rev-parse --short HEAD)
docker tag helbaron-api:1.0.0-rc.1 helbaron-api:sha-$SHORT_SHA
docker tag helbaron-web:1.0.0-rc.1 helbaron-web:sha-$SHORT_SHA
```

## Checksums (record after build; do not fabricate)
```
docker image inspect helbaron-api:1.0.0-rc.1 --format '{{index .RepoDigests 0}}'
docker image inspect helbaron-web:1.0.0-rc.1 --format '{{index .RepoDigests 0}}'
```
Record the `sha256:` digests here once the images are built on the release host.

## SBOM (when tooling is available)
```
trivy image --format cyclonedx --output sbom-api.cdx.json  helbaron-api:1.0.0-rc.1
trivy image --format cyclonedx --output sbom-web.cdx.json  helbaron-web:1.0.0-rc.1
```
(Trivy is already installed on the release host per W08.) Store the two CycloneDX SBOMs
alongside this manifest.

## Vulnerability posture at RC (W08/W09 evidence)
- API image: Trivy `--severity HIGH,CRITICAL --ignore-unfixed` → **0**.
- Web image: **0** after `postcss`→8.5.x / `sharp`→0.35.x remediation.
Re-run on the release host as the final gate (the `W09_WINDOWS_UAT.ps1` script does this).

## Runtime prerequisites
- Real `apps/api/.env.production` and `apps/web/.env.production` (never committed). The
  API boot guard refuses placeholder/fake config.
- Externally managed PostgreSQL 16 + Redis, or the bundled compose services for staging.
- TLS terminates upstream (ALB/CloudFront); nginx speaks HTTP on the private network,
  published on host `:8080`.

## Rollback
No schema changes in this candidate — rollback is an image swap to the previous
(W08) digests + service restart. See `v1.0.0-rc.1.md`.
