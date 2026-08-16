import { NextRequest, NextResponse } from "next/server";

/**
 * Same-origin BFF proxy to the Laravel REST API. Attaches the Sanctum token from the
 * httpOnly session cookie server-side, so browser JS never handles the credential.
 * Non-GET requests are rejected when the Origin header does not match (CSRF guard on
 * top of SameSite=Lax).
 */
const SESSION_COOKIE = "helbaron_session";

const API_BASE =
  process.env.API_INTERNAL_URL ??
  process.env.NEXT_PUBLIC_API_BASE_URL ??
  "http://localhost:8000/api/v1";

/** Request headers forwarded to the API. */
const FORWARD_REQUEST_HEADERS = ["content-type", "accept-language", "x-correlation-id"] as const;
/** Response headers passed back to the browser. */
const FORWARD_RESPONSE_HEADERS = [
  "content-type",
  "x-correlation-id",
  "retry-after",
  "x-ratelimit-limit",
  "x-ratelimit-remaining",
] as const;

/**
 * CSRF origin check. Accepts an Origin only when it matches a host this deployment serves: the host
 * Next resolved for the request, or the configured canonical site host. `nextUrl.host` alone is not
 * enough — behind a proxy that rewrites Host, or on a dev server reached by a hostname other than
 * the one it bound to, it differs from the browser's Origin and every mutation would 403. Adding
 * only the operator's own configured origin leaves third-party origins refused as before.
 */
function allowedHosts(req: NextRequest): Set<string> {
  const hosts = new Set([req.nextUrl.host]);
  const site = process.env.NEXT_PUBLIC_SITE_URL;
  if (site) {
    try {
      hosts.add(new URL(site).host);
    } catch {
      // A malformed NEXT_PUBLIC_SITE_URL simply contributes no additional host.
    }
  }
  return hosts;
}

function crossOrigin(req: NextRequest): boolean {
  const origin = req.headers.get("origin");
  if (!origin) return false;
  try {
    return !allowedHosts(req).has(new URL(origin).host);
  } catch {
    return true;
  }
}

async function proxy(
  req: NextRequest,
  ctx: { params: Promise<{ path: string[] }> },
): Promise<NextResponse> {
  if (req.method !== "GET" && req.method !== "HEAD" && crossOrigin(req)) {
    return NextResponse.json(
      { error: { code: "CSRF_ORIGIN_MISMATCH", message: "Cross-origin request rejected." } },
      { status: 403 },
    );
  }

  const { path } = await ctx.params;
  // Re-encode each decoded segment to prevent path injection into the upstream URL.
  const target = `${API_BASE}/${path.map(encodeURIComponent).join("/")}${req.nextUrl.search}`;

  const headers: Record<string, string> = { Accept: "application/json" };
  for (const name of FORWARD_REQUEST_HEADERS) {
    const value = req.headers.get(name);
    if (value) headers[name] = value;
  }

  const token = req.cookies.get(SESSION_COOKIE)?.value;
  if (token) headers.Authorization = `Bearer ${token}`;

  const rawBody =
    req.method === "GET" || req.method === "HEAD" ? undefined : await req.arrayBuffer();

  let res: Response;
  try {
    res = await fetch(target, {
      method: req.method,
      headers,
      body: rawBody && rawBody.byteLength > 0 ? rawBody : undefined,
      cache: "no-store",
      redirect: "manual",
    });
  } catch {
    return NextResponse.json(
      { error: { code: "UPSTREAM_UNAVAILABLE", message: "The API is unreachable." } },
      { status: 502 },
    );
  }

  const resBody = res.status === 204 ? null : await res.arrayBuffer();
  const out = new NextResponse(resBody, { status: res.status });
  for (const name of FORWARD_RESPONSE_HEADERS) {
    const value = res.headers.get(name);
    if (value) out.headers.set(name, value);
  }
  return out;
}

export { proxy as GET, proxy as POST, proxy as PUT, proxy as PATCH, proxy as DELETE };
