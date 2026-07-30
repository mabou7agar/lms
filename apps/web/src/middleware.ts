import { NextRequest, NextResponse } from "next/server";

/**
 * Server-side route protection: authenticated areas redirect to /login (preserving the
 * intended destination) when no session cookie is present. This complements — not replaces —
 * the client-side guards and the API's own authorization: the middleware only checks cookie
 * presence; token validity is enforced by the API on every proxied request.
 */
const SESSION_COOKIE = "helbaron_session";

// Real URL prefixes only — NOT route-group folder names like "(account)". "/account" was a phantom
// entry (no such URL), leaving the actual account routes (/profile, /notifications) with no edge
// check; the commerce workspace (/billing, /subscriptions, /cart) and the admin console (/admin)
// were likewise unguarded at the edge and relied solely on client-side guards.
const PROTECTED_PREFIXES = [
  "/profile",
  "/notifications",
  "/dashboard",
  "/my-learning",
  "/continue-learning",
  "/certificates",
  "/learn",
  "/lessons",
  "/teach",
  "/crm",
  "/org",
  "/orders",
  "/cart",
  "/checkout",
  "/contracts",
  "/billing",
  "/subscriptions",
  "/admin",
  "/analytics",
  "/reports",
  "/dashboards",
];

export function middleware(req: NextRequest): NextResponse {
  const { pathname } = req.nextUrl;

  const isProtected = PROTECTED_PREFIXES.some(
    (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
  );
  if (!isProtected) return NextResponse.next();

  if (req.cookies.get(SESSION_COOKIE)?.value) return NextResponse.next();

  const login = req.nextUrl.clone();
  login.pathname = "/login";
  login.search = "";
  login.searchParams.set("redirect", pathname + req.nextUrl.search);
  return NextResponse.redirect(login);
}

export const config = {
  // Skip static assets, Next internals, and the BFF API routes themselves.
  matcher: ["/((?!_next|api|favicon.ico|.*\\..*).*)"],
};
