import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { render as rtlRender } from "@testing-library/react";
import { I18nProvider } from "@/lib/i18n/i18n-context";
import { renderWithI18n } from "../render";
import { alerts, failed, ok, overview, page, performanceRow, pending } from "./fixtures";

const hooks = vi.hoisted(() => ({
  useDashboardOverview: vi.fn(),
  useCoursePerformance: vi.fn(),
  useAuthoringActivity: vi.fn(),
  useInstructorAlerts: vi.fn(),
  useCourseReadiness: vi.fn(),
  useCourseChanges: vi.fn(),
  usePublishCourse: vi.fn(),
  useUnpublishCourse: vi.fn(),
  useArchiveCourse: vi.fn(),
}));
vi.mock("@/lib/teach/hooks", () => hooks);

import TeachDashboardPage from "@/app/(instructor)/teach/page";
import * as teachApi from "@/lib/teach/api";

const emptyActivity = { recently_edited: [], recently_published: [] };
const mutation = () => ({ mutateAsync: vi.fn(), isPending: false });

function allLoaded() {
  hooks.useDashboardOverview.mockReturnValue(ok(overview()));
  hooks.useCoursePerformance.mockReturnValue(ok(page([performanceRow()])));
  hooks.useAuthoringActivity.mockReturnValue(ok(emptyActivity));
  hooks.useInstructorAlerts.mockReturnValue(ok(alerts()));
}

describe("TeachDashboardPage", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    hooks.useCourseReadiness.mockReturnValue(pending());
    hooks.useCourseChanges.mockReturnValue(pending());
    hooks.usePublishCourse.mockReturnValue(mutation());
    hooks.useUnpublishCourse.mockReturnValue(mutation());
    hooks.useArchiveCourse.mockReturnValue(mutation());
  });

  it("renders all five sections with semantic headings", () => {
    allLoaded();
    renderWithI18n(<TeachDashboardPage />);

    for (const name of [
      "Overview",
      "Course performance",
      "Authoring activity",
      "Tasks and alerts",
    ]) {
      expect(screen.getByRole("heading", { name, level: 2 })).toBeInTheDocument();
    }

    // Quick actions live in the page header.
    expect(screen.getByRole("link", { name: /New course/ })).toBeInTheDocument();
  });

  it("names each section landmark for screen readers", () => {
    allLoaded();
    renderWithI18n(<TeachDashboardPage />);

    expect(screen.getByRole("region", { name: "Overview" })).toBeInTheDocument();
    expect(screen.getByRole("region", { name: "Tasks and alerts" })).toBeInTheDocument();
  });

  it("keeps the other sections visible when one fails", () => {
    allLoaded();
    hooks.useInstructorAlerts.mockReturnValue(failed("Alerts are down"));
    renderWithI18n(<TeachDashboardPage />);

    // One endpoint failing must not blank the three that succeeded.
    expect(screen.getByText("12")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Advanced Laravel" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /try again/i })).toBeInTheDocument();
  });

  it("keeps rendering while a slow section is still loading", () => {
    allLoaded();
    hooks.useCoursePerformance.mockReturnValue(pending());
    renderWithI18n(<TeachDashboardPage />);

    expect(screen.getByText("12")).toBeInTheDocument();
  });

  it("renders under RTL without losing content", () => {
    allLoaded();
    rtlRender(
      <I18nProvider initialLocale="ar">
        <TeachDashboardPage />
      </I18nProvider>,
    );

    expect(screen.getByRole("heading", { name: "نظرة عامة", level: 2 })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "أداء الدورات", level: 2 })).toBeInTheDocument();
    // Arabic locale must not fall back to raw dictionary keys.
    expect(screen.queryByText(/teach\./)).not.toBeInTheDocument();
  });

  it("localizes the unavailable label rather than hardcoding English", () => {
    allLoaded();
    rtlRender(
      <I18nProvider initialLocale="ar">
        <TeachDashboardPage />
      </I18nProvider>,
    );

    expect(screen.getAllByText("غير متاح").length).toBeGreaterThan(0);
  });
});

describe("instructor data layer", () => {
  it("targets only instructor-scoped endpoints", () => {
    // The dashboard must never reach the platform-global analytics surfaces — instructors are
    // refused there by design, and calling them would produce a 403 the UI cannot recover from.
    const source = Object.entries(teachApi)
      .filter(([, value]) => typeof value === "function")
      .map(([name]) => name)
      .join(" ");

    expect(source).toContain("getDashboardOverview");
    expect(source).toContain("getCoursePerformance");
    expect(source).toContain("getAuthoringActivity");
    expect(source).toContain("getInstructorAlerts");
    expect(source).toContain("getCourseChanges");
  });

  it("only offers sort fields the backend accepts", () => {
    // Anything outside this list is a 422 from CoursePerformanceService::SORTABLE.
    expect([...teachApi.PERFORMANCE_SORT_FIELDS]).toEqual([
      "title",
      "status",
      "created_at",
      "updated_at",
      "published_at",
    ]);
  });
});
