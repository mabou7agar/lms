import { NextResponse } from "next/server";

// Liveness probe for the web tier. Dependency-free BY DESIGN: it answers only
// "is this Next.js server process up and serving?" for load balancers and container
// orchestration. It MUST NOT check the backend API, the database, or any external
// dependency — downstream readiness is the API's concern (GET /api/v1/health/ready),
// and a briefly-unavailable dependency must never evict the web container from rotation.
export const runtime = "nodejs";
export const dynamic = "force-dynamic";

export function GET() {
  return NextResponse.json(
    {
      status: "ok",
      service: "web",
      version: process.env.NEXT_PUBLIC_APP_VERSION ?? "unknown",
    },
    {
      status: 200,
      headers: { "Cache-Control": "no-store, max-age=0" },
    },
  );
}
