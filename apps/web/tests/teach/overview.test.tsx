import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { renderWithI18n } from "../render";
import { available, failed, ok, overview, pending, unavailable } from "./fixtures";

const { useDashboardOverview } = vi.hoisted(() => ({ useDashboardOverview: vi.fn() }));
vi.mock("@/lib/teach/hooks", () => ({ useDashboardOverview }));

import { OverviewSection } from "@/components/teach/overview-section";

describe("OverviewSection", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders available metrics with their real values", () => {
    useDashboardOverview.mockReturnValue(ok(overview()));
    renderWithI18n(<OverviewSection />);

    expect(screen.getByText("12")).toBeInTheDocument();
    expect(screen.getByText("1,234")).toBeInTheDocument();
  });

  it("formats a percentage metric as a percentage, not a raw number", () => {
    useDashboardOverview.mockReturnValue(ok(overview({ completion_rate: available(42) })));
    renderWithI18n(<OverviewSection />);

    // The backend sends 42 meaning 42%. Feeding that straight to Intl's percent style would
    // render 4,200% — this pins the division.
    expect(screen.getByText("42%")).toBeInTheDocument();
    expect(screen.queryByText("4,200%")).not.toBeInTheDocument();
  });

  it("shows Unavailable and the backend reason instead of a zero", () => {
    useDashboardOverview.mockReturnValue(ok(overview()));
    renderWithI18n(<OverviewSection />);

    expect(screen.getAllByText("Unavailable").length).toBeGreaterThanOrEqual(3);
    expect(
      screen.getByText("Revenue analytics are not available for instructors yet."),
    ).toBeInTheDocument();
    expect(
      screen.getByText("At-risk learner detection is not configured."),
    ).toBeInTheDocument();
  });

  it("never prints 0 for an unavailable metric", () => {
    useDashboardOverview.mockReturnValue(
      ok(
        overview({
          total_courses: unavailable("Not computed."),
          total_learners: unavailable("Not computed."),
        }),
      ),
    );
    renderWithI18n(<OverviewSection />);

    // The bug this guards: coercing `value: null` to 0 and telling an instructor they have no
    // courses when the platform simply could not answer.
    expect(screen.queryByText("0")).not.toBeInTheDocument();
  });

  it("still renders an unavailable metric rather than hiding it", () => {
    useDashboardOverview.mockReturnValue(ok(overview()));
    renderWithI18n(<OverviewSection />);

    // Hiding it would leave an instructor unsure whether the platform tracks revenue at all.
    expect(screen.getByText("Revenue")).toBeInTheDocument();
  });

  it("renders a skeleton while loading", () => {
    useDashboardOverview.mockReturnValue(pending());
    const { container } = renderWithI18n(<OverviewSection />);

    expect(container.querySelectorAll(".motion-pulse").length).toBeGreaterThan(0);
  });

  it("shows a retry affordance when the overview fails", () => {
    const query = failed("Overview exploded");
    useDashboardOverview.mockReturnValue(query);
    renderWithI18n(<OverviewSection />);

    expect(screen.getByRole("alert")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /try again/i })).toBeInTheDocument();
  });
});
