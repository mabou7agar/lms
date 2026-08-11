import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }), usePathname: () => "/enterprise" }));
const trackMock = vi.fn((..._a: unknown[]) => ({ event: "x", v: 1, props: {} }));
vi.mock("@/lib/analytics/track", () => ({ track: (...a: unknown[]) => trackMock(...a) }));
const calls = (): unknown[][] => trackMock.mock.calls as unknown as unknown[][];

import { EnterprisePage, ENTERPRISE_FAQ } from "@/components/marketing/enterprise-page";

beforeEach(() => trackMock.mockClear());

describe("enterprise page", () => {
  it("fires page_view once and routes the demo CTA to the in-page lead form", () => {
    renderWithI18n(<EnterprisePage />);
    expect(calls().filter((c) => c[0] === "page_view").length).toBe(1);
    const demo = screen.getAllByRole("link", { name: /Request a demo/i })[0];
    expect(demo).toHaveAttribute("href", "#request-demo");
  });

  it("emits enterprise_demo_started on the demo CTA and NEVER enterprise_demo_submitted", async () => {
    renderWithI18n(<EnterprisePage />);
    await userEvent.click(screen.getAllByRole("link", { name: /Request a demo/i })[0]);
    expect(calls().some((c) => c[0] === "enterprise_demo_started")).toBe(true);
    expect(calls().some((c) => c[0] === "enterprise_demo_submitted")).toBe(false);
    // no PII in payloads
    for (const c of calls()) {
      for (const k of Object.keys((c[1] ?? {}) as Record<string, unknown>)) {
        expect(/email|phone|name|password|token|card|auth|otp|message/i.test(k)).toBe(false);
      }
    }
  });

  it("renders real capabilities and the enterprise FAQ", () => {
    renderWithI18n(<EnterprisePage />);
    expect(screen.getByText(/Organization & member management/i)).toBeInTheDocument();
    expect(screen.getByText(/Reporting & analytics/i)).toBeInTheDocument();
    // "Verifiable certificates" also appears in the hero promise, so assert the capability card exists.
    expect(screen.getAllByText(/Verifiable certificates/i).length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText(ENTERPRISE_FAQ[0].q.en)).toBeInTheDocument();
  });

  it("claims no ISO/SOC2/SLA/certification it does not hold", () => {
    const { container } = renderWithI18n(<EnterprisePage />);
    const text = container.textContent ?? "";
    // ISO/SOC2 only appear inside the honest disclaimer FAQ ("we do not claim..."); assert no positive claim.
    expect(/\bISO\s?\d{4,5}\b/.test(text)).toBe(false);
    expect(/\bcertified\b/i.test(text)).toBe(false);
    expect(/\bSLA\b/.test(text)).toBe(false);
    expect(/\bguarantee/i.test(text)).toBe(false);
  });
});
