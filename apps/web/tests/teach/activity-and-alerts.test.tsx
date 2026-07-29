import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { renderWithI18n } from "../render";
import { alerts, failed, ok, pending } from "./fixtures";

const hooks = vi.hoisted(() => ({
  useAuthoringActivity: vi.fn(),
  useInstructorAlerts: vi.fn(),
}));
vi.mock("@/lib/teach/hooks", () => hooks);

import { ActivitySection } from "@/components/teach/activity-section";
import { AlertsSection } from "@/components/teach/alerts-section";

describe("ActivitySection", () => {
  beforeEach(() => vi.clearAllMocks());

  it("lists recently edited and recently published courses", () => {
    hooks.useAuthoringActivity.mockReturnValue(
      ok({
        recently_edited: [
          { id: "c1", title: "Draft in progress", status: "draft", occurred_at: "2026-07-18T09:00:00+00:00" },
        ],
        recently_published: [
          { id: "c2", title: "Shipped course", status: "published", occurred_at: "2026-07-10T09:00:00+00:00" },
        ],
      }),
    );
    renderWithI18n(<ActivitySection />);

    expect(screen.getByRole("link", { name: "Draft in progress" })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Shipped course" })).toBeInTheDocument();
  });

  it("shows an empty state per stream", () => {
    hooks.useAuthoringActivity.mockReturnValue(
      ok({ recently_edited: [], recently_published: [] }),
    );
    renderWithI18n(<ActivitySection />);

    expect(screen.getByText("No course edits recorded yet.")).toBeInTheDocument();
    expect(screen.getByText("You have not published a course yet.")).toBeInTheDocument();
  });

  it("renders a machine-readable timestamp", () => {
    hooks.useAuthoringActivity.mockReturnValue(
      ok({
        recently_edited: [
          { id: "c1", title: "Course", status: "draft", occurred_at: "2026-07-18T09:00:00+00:00" },
        ],
        recently_published: [],
      }),
    );
    const { container } = renderWithI18n(<ActivitySection />);

    expect(container.querySelector("time")).toHaveAttribute(
      "dateTime",
      "2026-07-18T09:00:00+00:00",
    );
  });
});

describe("AlertsSection", () => {
  beforeEach(() => vi.clearAllMocks());

  it("lists publish blockers with the first blocker's own words", () => {
    hooks.useInstructorAlerts.mockReturnValue(
      ok(
        alerts({
          publish_blockers: [
            {
              id: "c1",
              title: "Empty course",
              status: "draft",
              blocker_count: 1,
              first_blocker: "The course has no sections.",
            },
          ],
        }),
      ),
    );
    renderWithI18n(<AlertsSection onReviewReadiness={vi.fn()} />);

    expect(screen.getByText("Empty course")).toBeInTheDocument();
    expect(screen.getByText("The course has no sections.")).toBeInTheDocument();
  });

  it("opens readiness for the blocked course", async () => {
    const user = userEvent.setup();
    const onReviewReadiness = vi.fn();
    hooks.useInstructorAlerts.mockReturnValue(
      ok(
        alerts({
          publish_blockers: [
            { id: "c1", title: "Empty course", status: "draft", blocker_count: 1, first_blocker: null },
          ],
        }),
      ),
    );
    renderWithI18n(<AlertsSection onReviewReadiness={onReviewReadiness} />);

    await user.click(screen.getByRole("button", { name: "Review readiness" }));

    expect(onReviewReadiness).toHaveBeenCalledWith("c1");
  });

  it("states plainly when only part of the catalogue was evaluated", () => {
    hooks.useInstructorAlerts.mockReturnValue(
      ok(
        alerts({
          readiness_coverage: { evaluated_count: 50, total_count: 214, truncated: true, limit: 50 },
        }),
      ),
    );
    renderWithI18n(<AlertsSection onReviewReadiness={vi.fn()} />);

    expect(screen.getByRole("status")).toHaveTextContent(
      "Only 50 of your 214 courses were checked for readiness.",
    );
  });

  it("does not claim all-clear when the sweep was truncated", () => {
    hooks.useInstructorAlerts.mockReturnValue(
      ok(
        alerts({
          readiness_coverage: { evaluated_count: 50, total_count: 214, truncated: true, limit: 50 },
        }),
      ),
    );
    renderWithI18n(<AlertsSection onReviewReadiness={vi.fn()} />);

    // The failure this guards: a partial sweep with no findings rendering as a clean bill of health.
    expect(screen.queryByText("Nothing needs your attention right now.")).not.toBeInTheDocument();
  });

  it("says all-clear only when the whole catalogue was checked and nothing was found", () => {
    hooks.useInstructorAlerts.mockReturnValue(ok(alerts()));
    renderWithI18n(<AlertsSection onReviewReadiness={vi.fn()} />);

    expect(screen.getByText("Nothing needs your attention right now.")).toBeInTheDocument();
  });

  it("reports unavailable alert streams with their reason instead of an empty list", () => {
    hooks.useInstructorAlerts.mockReturnValue(ok(alerts()));
    renderWithI18n(<AlertsSection onReviewReadiness={vi.fn()} />);

    expect(screen.getByText("At-risk learner detection is not configured.")).toBeInTheDocument();
    expect(screen.getByText("Failed publish attempts are not recorded.")).toBeInTheDocument();
  });

  it("lists stale drafts and courses without learners", () => {
    hooks.useInstructorAlerts.mockReturnValue(
      ok(
        alerts({
          stale_drafts: [{ id: "c1", title: "Forgotten", last_updated_at: "2026-05-01T00:00:00+00:00" }],
          courses_without_learners: [{ id: "c2", title: "Nobody enrolled" }],
        }),
      ),
    );
    renderWithI18n(<AlertsSection onReviewReadiness={vi.fn()} />);

    expect(screen.getByText("Forgotten")).toBeInTheDocument();
    expect(screen.getByText("Nobody enrolled")).toBeInTheDocument();
  });

  it("offers a retry when alerts fail without hiding anything else", () => {
    hooks.useInstructorAlerts.mockReturnValue(failed("Alerts exploded"));
    renderWithI18n(<AlertsSection onReviewReadiness={vi.fn()} />);

    expect(screen.getByRole("button", { name: /try again/i })).toBeInTheDocument();
  });

  it("shows a skeleton while loading", () => {
    hooks.useInstructorAlerts.mockReturnValue(pending());
    const { container } = renderWithI18n(<AlertsSection onReviewReadiness={vi.fn()} />);

    expect(container.querySelector(".motion-pulse")).toBeInTheDocument();
  });
});
