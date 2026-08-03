import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }), usePathname: () => "/" }));
const trackMock = vi.fn((..._a: unknown[]) => ({ event: "x", v: 1, props: {} }));
vi.mock("@/lib/analytics/track", () => ({ track: (...a: unknown[]) => trackMock(...a) }));
const calls = (): unknown[][] => trackMock.mock.calls as unknown as unknown[][];

import { PersonaPaths } from "@/components/landing/persona-paths";

beforeEach(() => trackMock.mockClear());

describe("homepage persona-paths band", () => {
  it("links to the four persona routes, comparison, and pricing", () => {
    renderWithI18n(<PersonaPaths />);
    const hrefs = Array.from(document.querySelectorAll("a")).map((a) => a.getAttribute("href"));
    for (const h of ["/solutions/enterprise", "/solutions/academies", "/solutions/instructors", "/solutions/government", "/compare", "/pricing"]) {
      expect(hrefs, `missing link ${h}`).toContain(h);
    }
  });

  it("emits persona_selected (non-PII) on a persona click", async () => {
    renderWithI18n(<PersonaPaths />);
    const link = document.querySelector('a[href="/solutions/enterprise"]') as HTMLAnchorElement;
    await userEvent.click(link);
    expect(calls().some((c) => c[0] === "persona_selected")).toBe(true);
    for (const c of calls()) {
      for (const k of Object.keys((c[1] ?? {}) as Record<string, unknown>)) {
        expect(/email|phone|name|password|token|card|auth|otp|message/i.test(k)).toBe(false);
      }
    }
  });
});
