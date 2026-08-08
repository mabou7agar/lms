import { screen } from "@testing-library/react";
import { render as rtlRender } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { I18nProvider } from "@/lib/i18n/i18n-context";
import { renderWithI18n } from "../render";
import { failed, learnerProgress, ok, pending } from "./fixtures";

const hooks = vi.hoisted(() => ({ useLearnerProgress: vi.fn() }));
vi.mock("@/lib/teach/hooks", () => hooks);

import { LearnerProgressPanel } from "@/components/teach/learner-progress-panel";

const render = () => renderWithI18n(<LearnerProgressPanel courseId="crs-1" studentId="usr-1" />);

describe("LearnerProgressPanel", () => {
  beforeEach(() => vi.clearAllMocks());

  it("names the learner in the header (public identity only)", () => {
    hooks.useLearnerProgress.mockReturnValue(ok(learnerProgress()));
    render();

    expect(screen.getByRole("heading", { name: "Sara Learner", level: 1 })).toBeInTheDocument();
  });

  it("renders progress, watch time and lessons completed", () => {
    hooks.useLearnerProgress.mockReturnValue(ok(learnerProgress()));
    render();

    expect(screen.getByText("40%")).toBeInTheDocument();
    expect(screen.getByText("1h 30m")).toBeInTheDocument(); // 5400s
    expect(screen.getByText("8 / 20")).toBeInTheDocument();
    expect(screen.getByText("Eloquent Relationships")).toBeInTheDocument();
  });

  it("summarises the required-assessment outcome as counts, not per-assessment ids", () => {
    hooks.useLearnerProgress.mockReturnValue(ok(learnerProgress()));
    render();

    expect(screen.getByText("2 of 3 passed")).toBeInTheDocument();
    expect(screen.getByText("Not all passed")).toBeInTheDocument();
  });

  it("shows the no-assessments case rather than a zero summary", () => {
    hooks.useLearnerProgress.mockReturnValue(
      ok(learnerProgress({ assessments: { required: 0, passed: 0, all_required_passed: true } })),
    );
    render();

    expect(screen.getByText("No required assessments")).toBeInTheDocument();
    expect(screen.queryByText("0 of 0 passed")).not.toBeInTheDocument();
  });

  it("reflects certificate status", () => {
    hooks.useLearnerProgress.mockReturnValue(ok(learnerProgress({ certificate: { issued: true } })));
    render();

    expect(screen.getByText("Issued")).toBeInTheDocument();
  });

  it("falls back to 'No lesson in progress' when the learner has finished", () => {
    hooks.useLearnerProgress.mockReturnValue(ok(learnerProgress({ current_lesson: null })));
    render();

    expect(screen.getByText("No lesson in progress")).toBeInTheDocument();
  });

  it("renders a not-found empty state when the learner is missing", () => {
    hooks.useLearnerProgress.mockReturnValue(ok(null));
    render();

    expect(screen.getByText("Learner not found.")).toBeInTheDocument();
  });

  it("surfaces a retry affordance when the request fails", () => {
    hooks.useLearnerProgress.mockReturnValue(failed("Boom"));
    render();

    expect(screen.getByRole("button", { name: /try again/i })).toBeInTheDocument();
  });

  it("shows a loading state before data arrives", () => {
    hooks.useLearnerProgress.mockReturnValue(pending());
    render();

    expect(screen.queryByRole("heading", { name: "Sara Learner", level: 1 })).not.toBeInTheDocument();
  });

  it("localizes under RTL without falling back to raw keys", () => {
    hooks.useLearnerProgress.mockReturnValue(ok(learnerProgress()));
    rtlRender(
      <I18nProvider initialLocale="ar">
        <LearnerProgressPanel courseId="crs-1" studentId="usr-1" />
      </I18nProvider>,
    );

    expect(screen.getByText("وقت المشاهدة")).toBeInTheDocument();
    expect(screen.queryByText(/teach\./)).not.toBeInTheDocument();
  });
});
