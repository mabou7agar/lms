import type { NextConfig } from "next";

/** Origin of the backend API, derived from NEXT_PUBLIC_API_BASE_URL for the CSP connect-src. */
function apiOrigin(): string {
  const fallback = "http://localhost:8000";
  try {
    return new URL(process.env.NEXT_PUBLIC_API_BASE_URL ?? `${fallback}/api/v1`).origin;
  } catch {
    return fallback;
  }
}

// NOTE: 'unsafe-inline' in script-src is required by Next.js's inline runtime scripts
// until a nonce-based CSP is adopted. Production must NOT include 'unsafe-eval'.
// Next.js dev mode (Fast Refresh / webpack react-refresh) evaluates code with eval(),
// so the dev server needs 'unsafe-eval' — without it the client runtime throws and
// hydration never completes (client components, reveal animations, toggles all break).
// This is scoped to development only; production keeps the strict policy.
const isDev = process.env.NODE_ENV !== "production";
const scriptSrc = isDev
  ? "script-src 'self' 'unsafe-inline' 'unsafe-eval'"
  : "script-src 'self' 'unsafe-inline'";
const contentSecurityPolicy = [
  "default-src 'self'",
  scriptSrc,
  "style-src 'self' 'unsafe-inline'",
  "img-src 'self' data: blob: https:",
  "font-src 'self' data:",
  `connect-src 'self' ${apiOrigin()}`,
  "frame-src https://www.youtube-nocookie.com https://player.mux.com",
  "media-src 'self' blob: https:",
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'none'",
].join("; ");

const securityHeaders = [
  { key: "Content-Security-Policy", value: contentSecurityPolicy },
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=()" },
  // Harmless over plain HTTP; enforced once served over HTTPS.
  { key: "Strict-Transport-Security", value: "max-age=63072000; includeSubDomains" },
];

const nextConfig: NextConfig = {
  reactStrictMode: true,
  // Pin the file-tracing root to THIS app so Next stops inferring the monorepo root from the
  // sibling root-level package-lock.json (the "inferred your workspace root" build warning).
  // Correct tracing is what the standalone Docker runtime image (apps/web/Dockerfile) depends on.
  outputFileTracingRoot: __dirname,
  // Tree-shake named imports from these barrel packages into per-module imports. lucide-react,
  // recharts, @headlessui, @mui/* etc. are ALREADY in Next's built-in default list, so only the
  // packages absent from that default are listed here (verified against next/dist/server/config.js).
  experimental: {
    optimizePackageImports: [
      "@radix-ui/react-avatar",
      "@radix-ui/react-dialog",
      "@radix-ui/react-dropdown-menu",
      "@radix-ui/react-label",
      "@radix-ui/react-scroll-area",
      "@radix-ui/react-select",
      "@radix-ui/react-separator",
      "@radix-ui/react-slot",
      "@radix-ui/react-tabs",
      "vaul",
      "sonner",
    ],
  },
  // Strip console.* from the production client bundle, but keep error/warn (used for real diagnostics
  // and asserted by the E2E "no console errors" checks). Dev is unaffected.
  compiler: {
    removeConsole: process.env.NODE_ENV === "production" ? { exclude: ["error", "warn"] } : false,
  },
  // Standalone output powers the Docker runtime image (see apps/web/Dockerfile) and is the
  // default. The Playwright harness sets NEXT_DISABLE_STANDALONE=1 so its build can be served by
  // `next start`, which is incompatible with standalone output. Production/Docker is unchanged.
  output: process.env.NEXT_DISABLE_STANDALONE === "1" ? undefined : "standalone",
  // Linting runs as a dedicated CI gate (`npm run lint`); Next 15's in-build lint is
  // incompatible with eslint-config-next >= 16 and Next 16 removes it entirely.
  eslint: { ignoreDuringBuilds: true },
  async headers() {
    return [{ source: "/(.*)", headers: securityHeaders }];
  },
  async redirects() {
    return [
      { source: "/courses/:public_id/learn", destination: "/learn/:public_id", permanent: true },
      // Account pages live at /profile and /notifications (the (account) route group adds no URL
      // segment). Redirect legacy /account/* links to the real URLs.
      { source: "/account/profile", destination: "/profile", permanent: true },
      { source: "/account/notifications", destination: "/notifications", permanent: true },
      { source: "/account", destination: "/profile", permanent: true },
      // There is no standalone Settings domain; account management lives on Profile + Notifications.
      { source: "/settings", destination: "/profile", permanent: true },
      { source: "/account/settings", destination: "/profile", permanent: true },
      { source: "/crm/organizations", destination: "/crm/accounts", permanent: true },
    ];
  },
};

export default nextConfig;
