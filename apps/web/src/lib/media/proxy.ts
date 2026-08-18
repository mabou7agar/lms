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
 * Loopback aliases that address the SAME dev API but are different URL origins as strings. The API
 * builds its public media URLs from Laravel's APP_URL (http://localhost:8000/...), while the frontend
 * derives the API origin from NEXT_PUBLIC_API_BASE_URL, which is commonly set to http://127.0.0.1:8000
 * — so a strict `u.origin === apiOrigin()` comparison missed every media URL and left it absolute and
 * cross-origin, which is exactly the fetch failure this module exists to avoid.
 */
const LOOPBACK_HOSTS = new Set(["localhost", "127.0.0.1", "[::1]", "::1", "0.0.0.0"]);

/** True when both URLs address the same dev origin, treating loopback host aliases as equivalent. */
function isSameDevOrigin(url: URL, origin: string): boolean {
  if (url.origin === origin) return true;
  try {
    const target = new URL(origin);
    return (
      url.protocol === target.protocol &&
      url.port === target.port &&
      LOOPBACK_HOSTS.has(url.hostname) &&
      LOOPBACK_HOSTS.has(target.hostname)
    );
  } catch {
    return false;
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
    if (isSameDevOrigin(u, apiOrigin()) && u.pathname.startsWith("/media/public/")) {
      return u.pathname + u.search;
    }
  } catch {
    // Relative or unparseable URL: already same-origin (or a placeholder) — leave unchanged.
  }
  return url;
}
