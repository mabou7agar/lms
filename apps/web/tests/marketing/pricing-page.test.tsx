import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }), usePathname: () => "/pricing" }));
const trackMock = vi.fn((..._a: unknown[]) => ({ event: "x", v: 1, props: {} }));
vi.mock("@/lib/analytics/track", () => ({ track: (...a: unknown[]) => trackMock(...a) }));
const calls = (): unknown[][] => trackMock.mock.calls as unknown as unknown[][];

import { PricingPage } from "@/components/marketing/pricing-page";

beforeEach(() => trackMock.mockClear());

describe("pricing page", () => {
  it("fires pricing_viewed once on mount", () => {
    renderWithI18n(<PricingPage />);
    expect(calls().filter((c) => c[0] === "pricing_viewed").length).toBe(1);
  });

  it("routes purchase models to real catalog/cohort/enterprise routes", () => {
    renderWithI18n(<PricingPage />);
    expect(screen.getByRole("link", { name: /See course prices/i })).toHaveAttribute("href", "/courses");
    expect(screen.getByRole("link", { name: /See cohorts/i })).toHaveAttribute("href", "/cohorts");
    expect(screen.getByRole("link", { name: /Request a quote/i })).toHaveAttribute("href", "/enterprise");
  });

  it("emits plan_selected (non-PII) when a model CTA is clicked", async () => {
    renderWithI18n(<PricingPage />);
    await userEvent.click(screen.getByRole("link", { name: /See course prices/i }));
    const plan = calls().find((c) => c[0] === "plan_selected");
    expect(plan).toBeDefined();
    for (const c of calls()) {
      for (const k of Object.keys((c[1] ?? {}) as Record<string, unknown>)) {
        expect(/email|phone|name|password|token|card|auth|otp|message/i.test(k)).toBe(false);
      }
    }
  });

  it("renders a capability comparison and FAQ", () => {
    renderWithI18n(<PricingPage />);
    expect(screen.getByText(/Verifiable certificates/i)).toBeInTheDocument();
    expect(screen.getByText(/Organization administration/i)).toBeInTheDocument();
    expect(screen.getByText(/How much does a course cost/i)).toBeInTheDocument();
  });

  it("invents no price, trial, discount, guarantee, or SLA", () => {
    const { container } = renderWithI18n(<PricingPage />);
    const text = container.textContent ?? "";
    for (const re of [/\$|€|£|﷼/, /\bfree trial\b/i, /\btrial\b/i, /\d+\s?%/, /\bguarantee/i, /\bSLA\b/, /\bsave\b\s*\d/i, /\bdiscount/i]) {
      expect(re.test(text), `disallowed pricing claim (${re})`).toBe(false);
    }
  });
});
