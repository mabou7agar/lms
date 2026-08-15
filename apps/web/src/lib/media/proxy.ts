/** Origin of the backend API, derived the same way as next.config's CSP/rewrite helper. */
function apiOrigin(): string {
  const fallback = "http://localhost:8000";
  try {
    return new URL(process.env.NEXT_PUBLIC_API_BASE_URL ?? `${fallback}/api/v1`).origin;
  } catch {
    return fallback;
  }
}

/**
 * Dev-only: rewrite an absolute API-origin public-media URL (e.g. http://localhost:8000/media/public/…)
 * to a SAME-ORIGIN relative path (/media/public/…) so the browser fetches media from the Next dev
 * server, which proxies it to the API via the rewrite in next.config.ts.
 *
 * Why: in local dev the API is a different origin (http://localhost:8000) served by FrankenPHP/Octane.
 * Chromium's concurrent cross-origin <img> burst against that origin fails (hangs/503) on the Windows
 * Docker setup, even though every non-browser client (curl, container-internal, keep-alive) gets 200.
 * Routing media through the same Next origin removes the cross-origin variable entirely and uses the
 * Node dev server's request path (which handles the burst cleanly).
 *
 * In production this is a no-op: media is served from a CDN (a different origin than the API), so URLs
 * never match the dev API origin, and the NODE_ENV guard makes it identity regardless.
 */
export function proxyMediaUrl(url?: string | null): string | undefined {
  if (!url) return undefined;
  if (process.env.NODE_ENV === "production") return url;
  try {
    const u = new URL(url);
    if (u.origin === apiOrigin() && u.pathname.startsWith("/media/public/")) {
      return u.pathname + u.search;
    }
  } catch {
    // Relative or unparseable URL: already same-origin (or a placeholder) — leave unchanged.
  }
  return url;
}
