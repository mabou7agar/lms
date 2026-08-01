import { describe, it, expect } from "vitest";
import { GET } from "../../src/app/api/health/route";

describe("web liveness endpoint (/api/health)", () => {
  it("returns 200 with a liveness envelope and no-store caching", async () => {
    const res = GET();
    expect(res.status).toBe(200);
    expect(res.headers.get("cache-control")).toContain("no-store");
    const body = (await res.json()) as { status: string; service: string };
    expect(body.status).toBe("ok");
    expect(body.service).toBe("web");
  });
});
