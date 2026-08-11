import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";

const { useSeatSummary, useManagerReport, useDepartments, useTeams } = vi.hoisted(() => ({
  useSeatSummary: vi.fn(),
  useManagerReport: vi.fn(),
  useDepartments: vi.fn(),
  useTeams: vi.fn(),
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/enterprise/manager-hooks", () => ({ useSeatSummary, useManagerReport, useDepartments, useTeams }));

import ManagerDashboardPage from "@/app/(enterprise)/manager/page";

const ok = (data: unknown) => ({ isPending: false, isError: false, refetch: vi.fn(), data });
const paginated = (items: unknown[]) => ok({ data: items, meta: { current_page: 1, per_page: 25, total: items.length, last_page: 1 }, links: {} });

const REPORT = {
  organization_id: 1,
  learners: 42,
  enrollments: 100,
  started: 80,
  completions: 55,
  avg_progress: 63.4,
  watch_time_seconds: 7200,
  avg_watch_time_seconds_per_learner: 300,
  inactive_learners: 7,
  assessments_passed: 30,
  assessments_failed: 5,
  certificates_issued: 25,
  seats: { purchased: 50, used: 40, available: 10 },
};

describe("ManagerDashboardPage", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useDepartments.mockReturnValue(paginated([]));
    useTeams.mockReturnValue(paginated([]));
    useSeatSummary.mockReturnValue(ok({ subscription_id: "sub_1", status: "active", seats: { purchased: 50, used: 40, available: 10 } }));
    useManagerReport.mockReturnValue(ok(REPORT));
  });

  it("renders every learning-report metric", () => {
    renderWithI18n(<ManagerDashboardPage />);
    for (const label of [
      "Learners",
      "Enrollments",
      "Started",
      "Completions",
      "Average progress",
      "Watch time",
      "Avg watch time / learner",
      "Inactive learners",
      "Assessments passed",
      "Assessments failed",
      "Certificates issued",
      "Seat utilization %",
    ]) {
      expect(screen.getByText(label)).toBeInTheDocument();
    }
    // A couple of computed values.
    expect(screen.getByText("42")).toBeInTheDocument(); // learners
    expect(screen.getByText("63%")).toBeInTheDocument(); // rounded avg progress
  });

  it("renders the seat-utilization panel (purchased/used/available)", () => {
    renderWithI18n(<ManagerDashboardPage />);
    expect(screen.getByText("Purchased")).toBeInTheDocument();
    expect(screen.getByText("Used")).toBeInTheDocument();
    expect(screen.getByText("Available")).toBeInTheDocument();
  });
});
