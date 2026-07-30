import type { ReactNode } from "react";
import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";

const { useCommerceAnalytics } = vi.hoisted(() => ({ useCommerceAnalytics: vi.fn() }));

// AdminGuard reads the session from `@/lib/auth/guards`; stub that boundary so the analytics
// dashboard renders for a privileged user.
vi.mock("@/lib/auth/guards", () => ({
  RequireAuth: ({ children }: { children: ReactNode }) => <>{children}</>,
  useAuth: () => ({ status: "authenticated", user: { roles: ["admin"] } }),
}));
vi.mock("@/lib/auth/auth-context", () => ({
  useAuth: () => ({ status: "authenticated", user: { roles: ["admin"] } }),
}));
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  usePathname: () => "/admin/analytics",
  useSearchParams: () => new URLSearchParams(),
  useParams: () => ({}),
}));
vi.mock("@/lib/commerce/commerce-analytics-hooks", () => ({ useCommerceAnalytics }));

import AnalyticsPage from "@/app/(commerce)/admin/analytics/page";

describe("AnalyticsPage", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the KPI grid for an admin", () => {
    useCommerceAnalytics.mockReturnValue({
      isPending: false,
      isError: false,
      refetch: vi.fn(),
      data: {
        currency: "USD",
        range: { from: "2026-01-01", to: "2026-01-31" },
        revenue_minor: 1234500,
        net_revenue_minor: 1100000,
        refunds_minor: 134500,
        orders: 42,
        aov_minor: 29393,
        mrr_minor: 500000,
        active_subscribers: 87,
      },
    });

    renderWithI18n(<AnalyticsPage />);

    // i18n title and KPI labels.
    expect(screen.getByText("Commerce analytics")).toBeInTheDocument();
    expect(screen.getByText("Revenue")).toBeInTheDocument();
    expect(screen.getByText("Net revenue")).toBeInTheDocument();
    expect(screen.getByText("Refunds")).toBeInTheDocument();
    expect(screen.getByText("Active subscribers")).toBeInTheDocument();
    // Server-computed figures rendered from minor units / counts.
    expect(screen.getByText("$12,345.00")).toBeInTheDocument();
    expect(screen.getByText("42")).toBeInTheDocument();
    expect(screen.getByText("87")).toBeInTheDocument();
  });

  it("shows the empty state when every KPI is zero", () => {
    useCommerceAnalytics.mockReturnValue({
      isPending: false,
      isError: false,
      refetch: vi.fn(),
      data: {
        currency: "USD",
        range: { from: "2026-01-01", to: "2026-01-31" },
        revenue_minor: 0,
        net_revenue_minor: 0,
        refunds_minor: 0,
        orders: 0,
        aov_minor: 0,
        mrr_minor: 0,
        active_subscribers: 0,
      },
    });

    renderWithI18n(<AnalyticsPage />);
    // The header heading still renders even when the KPI body collapses to the empty state (whose
    // title reuses the same string, so scope the assertion to the page heading).
    expect(screen.getByRole("heading", { level: 1, name: "Commerce analytics" })).toBeInTheDocument();
  });
});
