# syntax=docker/dockerfile:1
# HElbaron Web — production image (Next.js 15, standalone output, non-root).
# Requires `output: "standalone"` in next.config.ts.

# ---- Stage 1: deps ----
FROM node:22-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

# ---- Stage 2: build ----
FROM node:22-alpine AS build
WORKDIR /app
ARG NEXT_PUBLIC_API_BASE_URL
ARG NEXT_PUBLIC_SITE_URL
ARG API_INTERNAL_URL
ENV NEXT_TELEMETRY_DISABLED=1 \
    NODE_ENV=production \
    NEXT_PUBLIC_API_BASE_URL=${NEXT_PUBLIC_API_BASE_URL} \
    NEXT_PUBLIC_SITE_URL=${NEXT_PUBLIC_SITE_URL} \
    API_INTERNAL_URL=${API_INTERNAL_URL}
COPY --from=deps /app/node_modules ./node_modules
COPY . .
RUN npm run build

# ---- Stage 3: runtime ----
FROM node:22-alpine AS runtime
WORKDIR /app
# wget is used by the container HEALTHCHECK below (busybox wget ships in alpine).
# Patch OS packages to their latest security-fixed versions (addresses fixable CRITICAL/HIGH OS
# CVEs the base image may lag on; Trivy scans the final image with ignore-unfixed).
# The production server runs `node server.js` and never invokes npm/npx/corepack; the base image's
# bundled package managers vendor libraries (e.g. sigstore) that carry CVEs irrelevant at runtime,
# so strip them to shrink the image and clear those findings.
RUN apk --no-cache upgrade \
    && rm -rf /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/corepack \
       /usr/local/bin/npm /usr/local/bin/npx /usr/local/bin/corepack
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    HOSTNAME=0.0.0.0 \
    PORT=3000

# Standalone server + static assets only — no toolchain, no full node_modules.
COPY --from=build --chown=node:node /app/.next/standalone ./
COPY --from=build --chown=node:node /app/.next/static ./.next/static
COPY --from=build --chown=node:node /app/public ./public

# Next's file tracing pulls esbuild's prebuilt Go binary (@esbuild/*) into the standalone bundle,
# but the runtime server (`node server.js`) never executes esbuild — it's build-only tooling. Its
# Go binary carries stdlib CVEs (e.g. CVE-2024-24790) that don't apply at runtime, so remove it to
# keep the production image minimal and clean. Safe: nothing at runtime imports esbuild.
RUN find ./node_modules -type d \( -name 'esbuild' -o -name '@esbuild' \) -prune -exec rm -rf {} + 2>/dev/null || true

USER node
EXPOSE 3000

# Dedicated liveness route (src/app/api/health/route.ts): dependency-free, returns 200 JSON.
# Probes the process itself, not the heavy homepage — a briefly-unavailable backend must never
# evict the web container from rotation (downstream readiness is /api/v1/health/ready on the API).
HEALTHCHECK --interval=15s --timeout=5s --retries=3 \
    CMD wget -qO- http://127.0.0.1:3000/api/health >/dev/null 2>&1 || exit 1

CMD ["node", "server.js"]
