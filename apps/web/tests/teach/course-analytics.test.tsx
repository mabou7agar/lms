import { screen } from "@testing-library/react";
import { render as rtlRender } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/lib/i18n/i18n-context";
import { renderWithI18n } from "../render";
import { courseAnalytics, failed, ok, pending, unavailable } from "./fixtures";

const hooks = vi.hoisted(() => ({ useCourseAnalytics: vi.fn() }));
vi.mock("@/lib/teach/hooks", () => hooks);

import { CourseAnalyticsSection } from "@/components/teach/course-analytics-section";

const render = () => renderWithI18n(<CourseAnalyticsSection courseId="crs-1" />);

describe("CourseAnalyticsSection", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the headline learner and certificate counts", () => {
    hooks.useCourseAnalytics.mockReturnValue(ok(courseAnalytics()));
    render();

    expect(screen.getByText("60")).toBeInTheDocument(); // total learners
    expect(screen.getByText("11")).toBeInTheDocument(); // certificates issued
    expect(screen.getByText("7")).toBeInTheDocument(); // inactive count
  });

  it("formats watch time as hours and minutes rather than raw seconds", () => {
    hooks.useCourseAnalytics.mockReturnValue(ok(courseAnalytics()));
    render();

    expect(screen.getByText("2h 30m")).toBeInTheDocument(); // total 9000s
    expect(screen.getByText("1h 15m")).toBeInTheDocument(); // avg 4500s
  });

  it("shows Unavailable and the reason for a watch-time metric with no data", () => {
    hooks.useCourseAnalytics.mockReturnValue(
      ok(
        courseAnalytics({
          watch_time: {
            total_watched_seconds: unavailable("No learners yet."),
            avg_watched_seconds_per_learner: unavailable("No learners yet."),
          },
        }),
      ),
    );
    render();

    expect(screen.getAllByText("Unavailable").length).toBeGreaterThan(0);
    expect(screen.getAllByText("No learners yet.").length).toBeGreaterThan(0);
  });

  it("flags the lesson with the biggest drop-off", () => {
    hooks.useCourseAnalytics.mockReturnValue(ok(courseAnalytics()));
    render();

    // The worst lesson (24 dropped) is flagged; the milder one (5) is not.
    expect(screen.getByText("Biggest drop-off")).toBeInTheDocument();
    expect(screen.getByText("Routing Deep Dive")).toBeInTheDocument();
    expect(screen.getByText("Getting Started")).toBeInTheDocument();
  });

  it("labels a removed lesson rather than rendering a blank funnel row", () => {
    hooks.useCourseAnalytics.mockReturnValue(
      ok(
        courseAnalytics({
          lesson_drop_off: [{ lesson: null, started: 10, completed: 2, drop_off: 8 }],
        }),
      ),
    );
    render();

    expect(screen.getByText("Removed lesson")).toBeInTheDocument();
  });

  it("renders every completion bucket, including empty ones", () => {
    hooks.useCourseAnalytics.mockReturnValue(ok(courseAnalytics()));
    render();

    for (const bucket of ["0%", "1-25%", "26-50%", "51-75%", "76-99%", "100%"]) {
      expect(screen.getByText(bucket)).toBeInTheDocument();
    }
  });

  it("shows an empty state when there is no engagement at all", () => {
    hooks.useCourseAnalytics.mockReturnValue(
      ok(
        courseAnalytics({
          total_learners: unavailable("No learners yet."),
          lesson_drop_off: [],
          completion_distribution: { "0": 0, "1-25": 0, "26-50": 0, "51-75": 0, "76-99": 0, "100": 0 },
        }),
      ),
    );
    render();

    expect(screen.getByText("No analytics available for this course yet.")).toBeInTheDocument();
  });

  it("surfaces a retry affordance when the request fails", () => {
    hooks.useCourseAnalytics.mockReturnValue(failed("Analytics down"));
    render();

    expect(screen.getByRole("button", { name: /try again/i })).toBeInTheDocument();
  });

  it("shows a skeleton while loading", () => {
    hooks.useCourseAnalytics.mockReturnValue(pending());
    const { container } = render();

    expect(container.querySelector(".motion-pulse")).toBeInTheDocument();
  });

  it("localizes under RTL without falling back to raw keys", () => {
    hooks.useCourseAnalytics.mockReturnValue(ok(courseAnalytics()));
    rtlRender(
      <I18nProvider initialLocale="ar">
        <CourseAnalyticsSection courseId="crs-1" />
      </I18nProvider>,
    );

    expect(screen.getByRole("heading", { name: "تسرّب الدروس", level: 2 })).toBeInTheDocument();
    expect(screen.queryByText(/teach\./)).not.toBeInTheDocument();
  });
});
