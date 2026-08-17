import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useReportInsight } = vi.hoisted(() => ({ useReportInsight: vi.fn() }));

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/reports/hooks", () => ({ useReportInsight, useReportCatalog: vi.fn() }));

import CommandCentrePage from "@/app/(analytics)/analytics/command-centre/page";

const ok = (data: unknown) => ({ isPending: false, isError: false, refetch: vi.fn(), data: { data, meta: null } });

const EXECUTIVE = {
  summary: {
    gross_revenue_minor: 6980000,
    net_revenue_minor: 6480000,
    refunds_minor: 500000,
    orders: 42,
    average_order_value_minor: 166190,
    certificates_issued: 17,
    seats_purchased: 60,
    seats_used: 41,
  },
  top_courses: [{ course: "Leading Teams", units: 12, revenue_minor: 2388000 }],
};

const FUNNEL = {
  summary: { course_views: 900, add_to_cart: 120, checkout_started: 60, orders_paid: 30 },
  tracking_since: "2026-08-01T00:00:00+00:00",
  funnel: [
    { stage: "viewed", count: 900 },
    { stage: "added_to_cart", count: 120 },
  ],
};

describe("Analytics command centre", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useReportInsight.mockReturnValue(ok(EXECUTIVE));
  });

  it("opens on the executive view and renders its figures", () => {
    renderWithI18n(<CommandCentrePage />);

    expect(screen.getByText(/Analytics command centre/i)).toBeInTheDocument();
    expect(screen.getByText("Leading Teams")).toBeInTheDocument();
  });

  it("switches which report it asks for when a tab is chosen", async () => {
    renderWithI18n(<CommandCentrePage />);

    // Opens on the executive summary...
    expect(useReportInsight).toHaveBeenCalledWith("admin_summary", expect.anything());

    await userEvent.click(screen.getByRole("button", { name: "Accounting" }));

    expect(useReportInsight).toHaveBeenLastCalledWith("accounting", expect.anything());
  });

  it("applies a date range to the report request", async () => {
    renderWithI18n(<CommandCentrePage />);

    // Both date inputs are `type="date"`; matched exactly so "To" does not also match "From".
    const [fromInput, toInput] = screen.getAllByLabelText(/^(From|To)$/);
    await userEvent.type(fromInput, "2026-01-01");
    await userEvent.type(toInput, "2026-06-30");
    await userEvent.click(screen.getByRole("button", { name: /^Apply$/i }));

    expect(useReportInsight).toHaveBeenLastCalledWith(
      "admin_summary",
      expect.objectContaining({ from: "2026-01-01", to: "2026-06-30" }),
    );
  });

  it("says the funnel is unmeasured rather than showing a zero that reads like a finding", async () => {
    useReportInsight.mockReturnValue(ok({ ...FUNNEL, tracking_since: null, summary: { course_views: 0 } }));

    renderWithI18n(<CommandCentrePage />);
    await userEvent.click(screen.getByRole("button", { name: "Marketing" }));

    expect(screen.getByText(/not measured rather than zero/i)).toBeInTheDocument();
  });

  it("states when tracking began once there is data", async () => {
    useReportInsight.mockReturnValue(ok(FUNNEL));

    renderWithI18n(<CommandCentrePage />);
    await userEvent.click(screen.getByRole("button", { name: "Marketing" }));

    expect(screen.getByText(/recording since/i)).toBeInTheDocument();
    expect(screen.queryByText(/not measured rather than zero/i)).not.toBeInTheDocument();
  });

  it("shows the caveat only on the funnel, not on the money views", () => {
    renderWithI18n(<CommandCentrePage />);

    expect(screen.queryByText(/recording since/i)).not.toBeInTheDocument();
  });

  it("exports the view that is on screen, named for its report and range", async () => {
    const click = vi.fn();
    const created: HTMLAnchorElement[] = [];
    const realCreate = document.createElement.bind(document);
    vi.spyOn(document, "createElement").mockImplementation((tag: string) => {
      const el = realCreate(tag);
      if (tag === "a") {
        (el as HTMLAnchorElement).click = click;
        created.push(el as HTMLAnchorElement);
      }
      return el;
    });
    // jsdom implements neither of these.
    URL.createObjectURL = vi.fn(() => "blob:report");
    URL.revokeObjectURL = vi.fn();

    renderWithI18n(<CommandCentrePage />);

    const [fromInput, toInput] = screen.getAllByLabelText(/^(From|To)$/);
    await userEvent.type(fromInput, "2026-01-01");
    await userEvent.type(toInput, "2026-06-30");
    await userEvent.click(screen.getByRole("button", { name: /^Apply$/i }));
    await userEvent.click(screen.getByRole("button", { name: /Export CSV/i }));

    expect(click).toHaveBeenCalledTimes(1);
    expect(created.at(-1)?.download).toBe("admin_summary-2026-01-01-2026-06-30.csv");
  });
});
