import { describe, expect, it, vi, beforeEach } from "vitest";

/**
 * A public page for something that does not exist must answer 404, not 200.
 *
 * Both course and bundle detail pages were pure client shells: the shell rendered, the browser
 * fetched, and the "not found" appeared client-side over an HTTP 200. A person sees the right
 * thing; a crawler indexes a real page, and a monitor sees a healthy response.
 *
 * The other half matters just as much: an API outage must NOT be laundered into a 404. A crawler
 * that gets a 404 for a real course drops it from the index, and it will not come back on its own.
 */

const { notFound, getCourse, getProduct } = vi.hoisted(() => ({
  notFound: vi.fn(() => {
    throw new Error("NEXT_NOT_FOUND");
  }),
  getCourse: vi.fn(),
  getProduct: vi.fn(),
}));

vi.mock("next/navigation", () => ({ notFound }));
vi.mock("@/lib/catalog/api", () => ({ getCourse }));
vi.mock("@/lib/commerce/api", () => ({ getProduct }));
vi.mock("@/lib/seo/api", () => ({ getSeo: vi.fn().mockResolvedValue(null) }));
vi.mock("@/lib/seo/locale", () => ({ resolveLocale: vi.fn().mockResolvedValue("en") }));
vi.mock("@/lib/seo/metadata", () => ({ buildMetadata: (_s: unknown, f: unknown) => f }));
vi.mock("@/app/(marketing)/(site)/courses/[public_id]/course-details-client", () => ({
  CourseDetailsClient: () => null,
}));
vi.mock("@/app/(marketing)/(site)/bundles/[public_id]/bundle-details-client", () => ({
  BundleDetailsClient: () => null,
}));

import CourseDetailsPage from "@/app/(marketing)/(site)/courses/[public_id]/page";
import BundleDetailPage from "@/app/(marketing)/(site)/bundles/[public_id]/page";

/** What the API client throws: an error carrying the HTTP status. */
const httpError = (status: number) => Object.assign(new Error(`HTTP ${status}`), { status });

const params = (id: string) => ({ params: Promise.resolve({ public_id: id }) });

describe("course detail not-found handling", () => {
  beforeEach(() => vi.clearAllMocks());

  it("404s when the course does not exist", async () => {
    getCourse.mockRejectedValue(httpError(404));

    await expect(CourseDetailsPage(params("01a00000-0000-7000-8000-000000000000"))).rejects.toThrow(
      "NEXT_NOT_FOUND",
    );
    expect(notFound).toHaveBeenCalled();
  });

  it("404s on a malformed id, which the API also reports as not found", async () => {
    getCourse.mockRejectedValue(httpError(404));

    await expect(CourseDetailsPage(params("not-a-uuid"))).rejects.toThrow("NEXT_NOT_FOUND");
  });

  it("renders for a course that exists", async () => {
    getCourse.mockResolvedValue({ id: "c1", title: "Real course" });

    await expect(CourseDetailsPage(params("c1"))).resolves.toBeTruthy();
    expect(notFound).not.toHaveBeenCalled();
  });

  it("does NOT 404 when the API is simply unreachable", async () => {
    getCourse.mockRejectedValue(new Error("fetch failed"));

    await expect(CourseDetailsPage(params("c1"))).resolves.toBeTruthy();
    expect(notFound).not.toHaveBeenCalled();
  });

  it("does NOT 404 on a server error from the API", async () => {
    getCourse.mockRejectedValue(httpError(503));

    await expect(CourseDetailsPage(params("c1"))).resolves.toBeTruthy();
    expect(notFound).not.toHaveBeenCalled();
  });
});

describe("bundle detail not-found handling", () => {
  beforeEach(() => vi.clearAllMocks());

  it("404s when the bundle does not exist", async () => {
    getProduct.mockRejectedValue(httpError(404));

    await expect(BundleDetailPage(params("01a00000-0000-7000-8000-000000000000"))).rejects.toThrow(
      "NEXT_NOT_FOUND",
    );
  });

  it("404s on a malformed id", async () => {
    getProduct.mockRejectedValue(httpError(404));

    await expect(BundleDetailPage(params("not-a-uuid"))).rejects.toThrow("NEXT_NOT_FOUND");
  });

  it("renders for a bundle that exists", async () => {
    getProduct.mockResolvedValue({ id: "b1", title: "Real bundle" });

    await expect(BundleDetailPage(params("b1"))).resolves.toBeTruthy();
    expect(notFound).not.toHaveBeenCalled();
  });

  it("does NOT 404 when the API is unreachable", async () => {
    getProduct.mockRejectedValue(new Error("fetch failed"));

    await expect(BundleDetailPage(params("b1"))).resolves.toBeTruthy();
    expect(notFound).not.toHaveBeenCalled();
  });
});
