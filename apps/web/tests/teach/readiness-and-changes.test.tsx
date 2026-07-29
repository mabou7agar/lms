import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { renderWithI18n } from "../render";
import { ok, pending } from "./fixtures";

const hooks = vi.hoisted(() => ({
  useCourseReadiness: vi.fn(),
  useCourseChanges: vi.fn(),
}));
vi.mock("@/lib/teach/hooks", () => hooks);

import { ChangeSummaryDialog } from "@/components/teach/change-summary-dialog";
import { ReadinessDialog } from "@/components/teach/readiness-dialog";

const report = (patch: Record<string, unknown> = {}) => ({
  is_publishable: true,
  score: 80,
  evaluated_at: "2026-07-19T10:00:00+00:00",
  blockers: [],
  warnings: [],
  passed_checks: ["course.no_sections", "course.missing_description"],
  ...patch,
});

const blocker = {
  code: "course.no_sections",
  severity: "blocker" as const,
  title: "The course has no sections.",
  explanation: "A course needs at least one section.",
  recommended_action: "Add a section in the Course Builder.",
  entity_type: "course" as const,
  entity_id: "crs-1",
};

const visibilityWarning = {
  code: "course.not_publicly_visible",
  severity: "warning" as const,
  title: 'The course visibility is "private", so it will not appear in the catalog.',
  explanation: "Only public courses are listed in the catalog.",
  recommended_action: "Set visibility to public in course settings.",
  entity_type: "course" as const,
  entity_id: "crs-1",
};

describe("ReadinessDialog", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    hooks.useCourseChanges.mockReturnValue(pending());
  });

  it("renders the score and passed-check count", () => {
    hooks.useCourseReadiness.mockReturnValue(ok(report()));
    renderWithI18n(<ReadinessDialog courseId="crs-1" onOpenChange={vi.fn()} />);

    expect(screen.getByText("80%")).toBeInTheDocument();
    expect(screen.getByText(/2 \/ 2/)).toBeInTheDocument();
  });

  it("lists blockers with their recommended action and a deep link", () => {
    hooks.useCourseReadiness.mockReturnValue(
      ok(report({ is_publishable: false, blockers: [blocker] })),
    );
    renderWithI18n(<ReadinessDialog courseId="crs-1" onOpenChange={vi.fn()} />);

    expect(screen.getByText("The course has no sections.")).toBeInTheDocument();
    expect(screen.getByText("Add a section in the Course Builder.")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Open in builder" })).toHaveAttribute(
      "href",
      "/teach/courses/crs-1/edit",
    );
  });

  it("reads is_publishable from the payload rather than recomputing it", () => {
    // A course with warnings but no blockers is publishable. Deriving the verdict from the issue
    // lists instead of the flag is how a panel drifts away from the guard it is meant to mirror.
    hooks.useCourseReadiness.mockReturnValue(
      ok(report({ is_publishable: true, warnings: [visibilityWarning] })),
    );
    renderWithI18n(<ReadinessDialog courseId="crs-1" onOpenChange={vi.fn()} />);

    expect(screen.getByText("Ready to publish")).toBeInTheDocument();
    expect(screen.queryByText("Not ready")).not.toBeInTheDocument();
  });

  it("says plainly that warnings do not block publishing", () => {
    hooks.useCourseReadiness.mockReturnValue(ok(report({ warnings: [visibilityWarning] })));
    renderWithI18n(<ReadinessDialog courseId="crs-1" onOpenChange={vi.fn()} />);

    expect(
      screen.getByText("Warnings do not prevent publishing. They are worth fixing, not required."),
    ).toBeInTheDocument();
  });

  it("labels severity in text, not by colour alone", () => {
    hooks.useCourseReadiness.mockReturnValue(
      ok(report({ is_publishable: false, blockers: [blocker], warnings: [visibilityWarning] })),
    );
    renderWithI18n(<ReadinessDialog courseId="crs-1" onOpenChange={vi.fn()} />);

    expect(screen.getByText("Blocker")).toBeInTheDocument();
    expect(screen.getByText("Warning")).toBeInTheDocument();
  });

  it("does not query while closed", () => {
    hooks.useCourseReadiness.mockReturnValue(pending());
    renderWithI18n(<ReadinessDialog courseId={null} onOpenChange={vi.fn()} />);

    expect(hooks.useCourseReadiness).toHaveBeenCalledWith("", false);
  });
});

describe("ChangeSummaryDialog", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    hooks.useCourseReadiness.mockReturnValue(pending());
  });

  it("shows the unavailable state with the backend reason", () => {
    hooks.useCourseChanges.mockReturnValue(
      ok({ available: false, reason: "No published baseline available." }),
    );
    renderWithI18n(<ChangeSummaryDialog courseId="crs-1" onOpenChange={vi.fn()} />);

    expect(screen.getByText("No published baseline available")).toBeInTheDocument();
    expect(screen.getByText("No published baseline available.")).toBeInTheDocument();
  });

  it("explains that snapshots are not implemented rather than implying nothing changed", () => {
    hooks.useCourseChanges.mockReturnValue(
      ok({ available: false, reason: "No published baseline available." }),
    );
    renderWithI18n(<ChangeSummaryDialog courseId="crs-1" onOpenChange={vi.fn()} />);

    expect(screen.getByText(/Publication snapshots are not implemented yet/)).toBeInTheDocument();
  });

  it("renders no change list at all when unavailable", () => {
    hooks.useCourseChanges.mockReturnValue(
      ok({ available: false, reason: "No published baseline available." }),
    );
    renderWithI18n(<ChangeSummaryDialog courseId="crs-1" onOpenChange={vi.fn()} />);

    // An empty list would read as "nothing has changed since you published" — a reassurance the
    // backend never gave. There must be no definition list to misread.
    expect(document.querySelector("dl")).toBeNull();
    expect(screen.queryByText("Sections added")).not.toBeInTheDocument();
  });

  it("renders structured categories when a real baseline eventually exists", () => {
    hooks.useCourseChanges.mockReturnValue(
      ok({
        available: true,
        baseline_published_at: "2026-01-01T00:00:00+00:00",
        changes: { sections_added: 2, lessons_removed: 1 },
      }),
    );
    renderWithI18n(<ChangeSummaryDialog courseId="crs-1" onOpenChange={vi.fn()} />);

    expect(screen.getByText("Sections added")).toBeInTheDocument();
    expect(screen.getByText("2")).toBeInTheDocument();
    expect(screen.getByText("Lessons removed")).toBeInTheDocument();
  });
});
