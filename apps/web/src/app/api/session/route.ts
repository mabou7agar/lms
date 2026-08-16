import { NextRequest, NextResponse } from "next/server";

/**
 * Session endpoint (BFF): exchanges credentials for a Sanctum token server-side and stores it
 * in an httpOnly, Secure, SameSite=Lax cookie. The token is never exposed to browser JS
 * (mitigates token exfiltration via XSS). A non-httpOnly marker cookie ("helbaron_authed")
 * lets the client know a session exists without revealing the credential.
 */
const SESSION_COOKIE = "helbaron_session";
const MARKER_COOKIE = "helbaron_authed";
const SESSION_MAX_AGE = 60 * 60 * 24 * 14; // 14 days

const API_BASE =
  process.env.API_INTERNAL_URL ??
  process.env.NEXT_PUBLIC_API_BASE_URL ??
  "http://localhost:8000/api/v1";

const secure = process.env.NODE_ENV === "production";

/**
 * CSRF origin check. An Origin is accepted only when it matches a host this deployment actually
 * serves: the host Next resolved for the request, or the configured canonical site host.
 *
 * The canonical host matters because `nextUrl.host` is not always the public hostname — behind a
 * reverse proxy that rewrites Host to the upstream, and on a dev server reached by a hostname other
 * than the one it bound to, it differs from the Origin the browser sends and every non-GET request
 * would be rejected. This widens the check to the operator's own configured origin only; an
 * arbitrary third-party Origin is still refused, so the CSRF protection is unchanged.
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

const forbidden = () =>
  NextResponse.json(
    { error: { code: "CSRF_ORIGIN_MISMATCH", message: "Cross-origin request rejected." } },
    { status: 403 },
  );

export async function POST(req: NextRequest): Promise<NextResponse> {
  if (crossOrigin(req)) return forbidden();

  const body = await req.json().catch(() => null);

  const res = await fetch(`${API_BASE}/auth/login`, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify(body ?? {}),
    cache: "no-store",
  });

  const json: unknown = await res.json().catch(() => null);

  if (!res.ok) {
    // Pass the API error envelope through untouched (includes MFA-required responses).
    return NextResponse.json(
      json ?? { error: { code: "HTTP_ERROR", message: res.statusText } },
      { status: res.status },
    );
  }

  const data = (json as { data?: { token?: string; user?: unknown } } | null)?.data;
  if (!data?.token) {
    return NextResponse.json(
      { error: { code: "AUTH_NO_TOKEN", message: "Login response did not include a token." } },
      { status: 502 },
    );
  }

  // Return the user but never the token.
  const out = NextResponse.json({ data: { user: data.user } }, { status: 200 });
  out.cookies.set(SESSION_COOKIE, data.token, {
    httpOnly: true,
    secure,
    sameSite: "lax",
    path: "/",
    maxAge: SESSION_MAX_AGE,
  });
  out.cookies.set(MARKER_COOKIE, "1", {
    httpOnly: false,
    secure,
    sameSite: "lax",
    path: "/",
    maxAge: SESSION_MAX_AGE,
  });
  return out;
}

export async function DELETE(req: NextRequest): Promise<NextResponse> {
  if (crossOrigin(req)) return forbidden();

  const token = req.cookies.get(SESSION_COOKIE)?.value;
  if (token) {
    // Best-effort server-side token revocation; the cookie is cleared regardless.
    await fetch(`${API_BASE}/auth/logout`, {
      method: "POST",
      headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      cache: "no-store",
    }).catch(() => undefined);
  }

  const out = new NextResponse(null, { status: 204 });
  out.cookies.set(SESSION_COOKIE, "", { httpOnly: true, secure, sameSite: "lax", path: "/", maxAge: 0 });
  out.cookies.set(MARKER_COOKIE, "", { httpOnly: false, secure, sameSite: "lax", path: "/", maxAge: 0 });
  return out;
}
