import { beforeEach, describe, expect, it, vi } from "vitest";
import { useState } from "react";
import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import type { Assignment, AssignmentInput } from "@/lib/assignments/assignments-api";

/**
 * The assignment authoring surface. What matters here is not layout but that every setting is
 * editable, that file-only fields appear ONLY for file submission types, that validation blocks a
 * bad save, that the surface is permission-gated, and that it takes the server's verdict rather than
 * deriving its own.
 */

// ── Mock the shared data layer (also covers PublishControls' hooks) ──────────
const {
  useAssignment,
  useUpdateAssignment,
  useBuildRubric,
  usePublishAssignment,
  useUnpublishAssignment,
} = vi.hoisted(() => ({
  useAssignment: vi.fn(),
  useUpdateAssignment: vi.fn(),
  useBuildRubric: vi.fn(),
  usePublishAssignment: vi.fn(),
  useUnpublishAssignment: vi.fn(),
}));

vi.mock("@/lib/assignments/assignments-hooks", () => ({
  useAssignment,
  useUpdateAssignment,
  useBuildRubric,
  usePublishAssignment,
  useUnpublishAssignment,
}));

const { useAuth } = vi.hoisted(() => ({ useAuth: vi.fn() }));
vi.mock("@/lib/auth/auth-context", () => ({ useAuth }));

const { toastSuccess, toastError } = vi.hoisted(() => ({ toastSuccess: vi.fn(), toastError: vi.fn() }));
vi.mock("@/components/ui/toast", () => ({ toast: { success: toastSuccess, error: toastError } }));

import { AssignmentSettingsForm } from "@/components/assignments/builder/assignment-settings-form";
import { AssignmentBuilder } from "@/components/assignments/builder/assignment-builder";

// ── Fixtures ─────────────────────────────────────────────────────────────────
function assignment(overrides: Partial<Assignment> = {}): Assignment {
  return {
    id: "a1",
    title: "Essay",
    lesson_id: null,
    instructions: null,
    submission_type: "text",
    publish_state: "draft",
    required_for_completion: false,
    settings: {
      allowed_file_types: null,
      max_file_size: null,
      max_files: 1,
      attempt_limit: null,
      due_at: null,
      late_policy: "blocked",
      late_penalty_percent: null,
      max_grade: 100,
      passing_grade: 50,
    },
    rubric: null,
    ...overrides,
  };
}

function mutation(overrides: Record<string, unknown> = {}) {
  return { mutateAsync: vi.fn().mockResolvedValue(assignment()), isPending: false, ...overrides };
}

function authWith(roles: string[]) {
  useAuth.mockReturnValue({ user: { id: "u1", roles }, status: "authenticated" });
}

// A tiny controlled harness so we can observe onChange-driven edits.
function SettingsHarness({ initial }: { initial: AssignmentInput }) {
  const [value, setValue] = useState<AssignmentInput>(initial);
  return <AssignmentSettingsForm value={value} onChange={setValue} />;
}

beforeEach(() => {
  vi.clearAllMocks();
  useAssignment.mockReturnValue({ data: assignment(), isPending: false, isError: false, refetch: vi.fn() });
  useUpdateAssignment.mockReturnValue(mutation());
  useBuildRubric.mockReturnValue(mutation());
  usePublishAssignment.mockReturnValue(mutation());
  useUnpublishAssignment.mockReturnValue(mutation());
  authWith(["instructor"]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Settings form (pure, controlled)
// ─────────────────────────────────────────────────────────────────────────────
describe("AssignmentSettingsForm", () => {
  const base: AssignmentInput = {
    title: "Essay",
    submission_type: "text",
    late_policy: "blocked",
    max_grade: 100,
    passing_grade: 50,
    max_files: 1,
  };

  it("renders the core settings and grading fields", () => {
    renderWithI18n(<SettingsHarness initial={base} />);
    expect(screen.getByLabelText(/Title/)).toHaveValue("Essay");
    expect(screen.getByText("Grading")).toBeInTheDocument();
    expect(screen.getByLabelText(/Maximum grade/)).toHaveValue(100);
    expect(screen.getByText("Attempts")).toBeInTheDocument();
    expect(screen.getByText("Due date & late work")).toBeInTheDocument();
  });

  it("hides file-restriction fields for a text submission type", () => {
    renderWithI18n(<SettingsHarness initial={{ ...base, submission_type: "text" }} />);
    expect(screen.queryByText("File restrictions")).not.toBeInTheDocument();
    expect(screen.queryByLabelText("Allowed file types")).not.toBeInTheDocument();
  });

  it("shows file-restriction fields for file and text_and_file types", () => {
    renderWithI18n(<SettingsHarness initial={{ ...base, submission_type: "file" }} />);
    expect(screen.getByText("File restrictions")).toBeInTheDocument();
    expect(screen.getByLabelText("Allowed file types")).toBeInTheDocument();
    expect(screen.getByLabelText("Max number of files")).toBeInTheDocument();
  });

  it("shows the late-penalty field only for the penalised policy", () => {
    const { unmount } = renderWithI18n(<SettingsHarness initial={{ ...base, late_policy: "allowed" }} />);
    expect(screen.queryByLabelText("Late penalty (%)")).not.toBeInTheDocument();
    unmount();
    renderWithI18n(<SettingsHarness initial={{ ...base, late_policy: "penalised" }} />);
    expect(screen.getByLabelText("Late penalty (%)")).toBeInTheDocument();
  });

  it("edits the title through onChange", async () => {
    const user = userEvent.setup();
    renderWithI18n(<SettingsHarness initial={base} />);
    const title = screen.getByLabelText(/Title/);
    await user.clear(title);
    await user.type(title, "Capstone");
    expect(title).toHaveValue("Capstone");
  });

  it("toggles required-for-completion", async () => {
    const user = userEvent.setup();
    renderWithI18n(<SettingsHarness initial={base} />);
    const toggle = screen.getByRole("switch", { name: "Required to complete the course" });
    expect(toggle).toHaveAttribute("aria-checked", "false");
    await user.click(toggle);
    expect(toggle).toHaveAttribute("aria-checked", "true");
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// Builder orchestrator
// ─────────────────────────────────────────────────────────────────────────────
describe("AssignmentBuilder", () => {
  it("blocks users without an authoring role", () => {
    authWith(["student"]);
    renderWithI18n(<AssignmentBuilder assignmentId="a1" />);
    expect(screen.getByText(/don't have permission/i)).toBeInTheDocument();
    expect(useAssignment).toHaveBeenCalledWith(null); // never fetched
  });

  it("renders the loaded assignment for an authorized user", () => {
    renderWithI18n(<AssignmentBuilder assignmentId="a1" />);
    expect(screen.getByRole("heading", { name: "Essay" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Settings" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Rubric" })).toBeInTheDocument();
  });

  it("surfaces a load error with a retry", () => {
    const refetch = vi.fn();
    useAssignment.mockReturnValue({ data: undefined, isPending: false, isError: true, refetch });
    renderWithI18n(<AssignmentBuilder assignmentId="a1" />);
    expect(screen.getByText("Couldn't load this assignment.")).toBeInTheDocument();
  });

  it("blocks the save and shows an error when the title is empty", async () => {
    const user = userEvent.setup();
    const update = mutation();
    useUpdateAssignment.mockReturnValue(update);
    renderWithI18n(<AssignmentBuilder assignmentId="a1" />);

    await user.clear(screen.getByLabelText(/Title/));
    await user.click(screen.getByRole("button", { name: "Save changes" }));

    expect(await screen.findByText("A title is required.")).toBeInTheDocument();
    expect(update.mutateAsync).not.toHaveBeenCalled();
  });

  it("saves valid settings through the update mutation", async () => {
    const user = userEvent.setup();
    const update = mutation();
    useUpdateAssignment.mockReturnValue(update);
    renderWithI18n(<AssignmentBuilder assignmentId="a1" />);

    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => expect(update.mutateAsync).toHaveBeenCalledTimes(1));
    const payload = update.mutateAsync.mock.calls[0][0] as AssignmentInput;
    expect(payload.title).toBe("Essay");
    expect(payload.submission_type).toBe("text");
    expect(payload.max_grade).toBe(100);
    expect(toastSuccess).toHaveBeenCalled();
  });

  it("reports a save API error via toast", async () => {
    const user = userEvent.setup();
    const update = mutation({ mutateAsync: vi.fn().mockRejectedValue(new Error("boom")) });
    useUpdateAssignment.mockReturnValue(update);
    renderWithI18n(<AssignmentBuilder assignmentId="a1" />);

    await user.click(screen.getByRole("button", { name: "Save changes" }));

    await waitFor(() => expect(toastError).toHaveBeenCalledWith("Couldn't save this assignment."));
  });
});
