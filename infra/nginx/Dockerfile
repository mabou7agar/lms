# syntax=docker/dockerfile:1
FROM nginx:1.27-alpine

# Bake routing into the release image. Dokploy replaces its Git checkout atomically, so bind mounts
# can otherwise remain attached to an older checkout when Compose keeps the proxy container alive.
COPY infra/nginx/nginx.conf /etc/nginx/conf.d/default.conf
