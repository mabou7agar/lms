import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it, vi } from "vitest";
import { REPORT_KEYS, getReportInsight } from "@/lib/reports/api";

/**
 * Regression guard for the Wave 7 "command centre 404s" defect.
 *
 * The insight catalog is keyed with underscores (`admin_summary`) but the API routes are
 * hyphenated (`reports/insights/admin-summary`), so src/lib/reports/api.ts carries a PATHS map to
 * translate. Adding a report server-side without adding its mapping produces a silent 404 for that
 * whole dashboard — which is exactly what happened to the three command-centre reports, because
 * every component test mocks the hook and therefore never builds a URL.
 *
 * This reads the real route file so a server-side report added without a web mapping fails here.
 */

const ROUTES = resolve(
  __dirname,
  "../../../api/app/Contexts/Analytics/routes/analytics.php",
);

/** Every path segment registered under the `reports/insights` prefix. */
function registeredSegments(): string[] {
  const source = readFileSync(ROUTES, "utf8");
  const block = source.slice(source.indexOf("Route::prefix('reports/insights')"));
  const body = block.slice(0, block.indexOf("});"));
  return [...body.matchAll(/Route::get\('([a-z-]+)'/g)]
    .map((m) => m[1])
    .filter((segment) => segment !== "catalog");
}

/** The segment getReportInsight() actually requests for a key. */
async function requestedSegment(key: string): Promise<string> {
  let requested = "";
  const client = await import("@/lib/api/client");
  const spy = vi.spyOn(client.api, "get").mockImplementation(async (path: string) => {
    requested = path;
    return { data: {}, meta: { from: "", to: "" } } as never;
  });
  await getReportInsight(key);
  spy.mockRestore();
  return requested.replace(/^reports\/insights\//, "").split("?")[0];
}

describe("report insight routes", () => {
  it("maps every registered API report to a web key", () => {
    const mapped = REPORT_KEYS.map((key) => key.replaceAll("_", "-"));
    expect(mapped).toEqual(expect.arrayContaining(registeredSegments()));
  });

  it("requests the hyphenated path the API actually serves", async () => {
    for (const key of REPORT_KEYS) {
      expect([key, await requestedSegment(key)]).toEqual([key, key.replaceAll("_", "-")]);
    }
  });

  it("covers the three command-centre reports", () => {
    expect(registeredSegments()).toEqual(
      expect.arrayContaining(["admin-summary", "marketing-funnel", "accounting"]),
    );
  });
});
