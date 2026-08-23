# syntax=docker/dockerfile:1
FROM nginx:1.27-alpine

# Bake routing into the release image. Dokploy replaces its Git checkout atomically, so bind mounts
# can otherwise remain attached to an older checkout when Compose keeps the proxy container alive.
COPY infra/nginx/nginx.conf /etc/nginx/conf.d/default.conf

# Filament publishes its browser assets into Laravel's public directory. The proxy is a separate
# image, so it must carry those immutable files itself; otherwise these URLs fall through to
# Next.js and render the admin panel as unstyled HTML.
COPY apps/api/public/css/filament /var/www/html/public/css/filament
COPY apps/api/public/js/filament /var/www/html/public/js/filament
COPY apps/api/public/fonts/filament /var/www/html/public/fonts/filament

RUN nginx -t
