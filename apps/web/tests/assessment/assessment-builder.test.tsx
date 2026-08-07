import type { ReactElement } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { screen, waitFor, within } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import type { Assessment, Question } from "@/lib/assessment/types";

/**
 * Slice 1 coverage: the quiz lesson ↔ assessment integration and the question list.
 *
 * The hooks layer is mocked (as every other suite here does) so these tests exercise the UI's
 * contract with it — what it renders, what it calls, and crucially what it does when the server
 * refuses. The API client itself is not re-tested; the backend Pest suite owns that.
 */

const { useAssessment, useAssessmentActions } = vi.hoisted(() => ({
  useAssessment: vi.fn(),
  useAssessmentActions: vi.fn(),
}));
const { createAssessment, setLessonAssessment } = vi.hoisted(() => ({
  createAssessment: vi.fn(),
  setLessonAssessment: vi.fn(),
}));
const { useBuilder } = vi.hoisted(() => ({ useBuilder: vi.fn() }));

vi.mock("@/lib/assessment/hooks", async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  useAssessment,
  useAssessmentActions,
}));
vi.mock("@/lib/assessment/api", () => ({ createAssessment, setLessonAssessment }));
vi.mock("@/lib/authoring/builder-store", () => ({ useBuilder }));

import { AssessmentBuilder } from "@/components/authoring/assessment/assessment-builder";
import { QuizLessonPanel } from "@/components/authoring/assessment/quiz-lesson-panel";
import type { Block } from "@/lib/authoring/types";

function question(overrides: Partial<Question> = {}): Question {
  return {
    id: "q1",
    type: "single_choice",
    prompt: "<p>Capital of France?</p>",
    config: null,
    explanation: null,
    hint: null,
    points: 1,
    negative_points: 0,
    difficulty: "medium",
    position: 0,
    options: [],
    ...overrides,
  };
}

function assessment(questions: Question[]): Assessment {
  return {
    id: "a1",
    title: "Module quiz",
    description: null,
    scope: "lesson",
    status: "draft",
    version: 1,
    settings: {
      passing_score: 60,
      negative_marking: false,
      max_attempts: null,
      time_limit_seconds: null,
      shuffle_questions: false,
      shuffle_options: false,
      questions_per_attempt: null,
      feedback_mode: "after_submit",
    },
    question_count: questions.length,
    questions,
  };
}

function quizBlock(overrides: Partial<Block> = {}): Block {
  return {
    id: "l1",
    title: "Knowledge check",
    title_i18n: { en: "Knowledge check", ar: "" },
    kind: "quiz",
    content: {},
    position: 0,
    publish_state: "draft",
    lock_version: 0,
    is_preview: false,
    media: null,
    prerequisites: [],
    assessment: null,
    ...overrides,
  };
}

const noopActions = () => ({
  updateSettings: vi.fn().mockResolvedValue(undefined),
  setStatus: vi.fn().mockResolvedValue(undefined),
  addQuestion: vi.fn().mockResolvedValue(question({ id: "new" })),
  saveQuestion: vi.fn().mockResolvedValue(undefined),
  removeQuestion: vi.fn().mockResolvedValue(undefined),
  reorder: vi.fn().mockResolvedValue(undefined),
});

beforeEach(() => {
  vi.clearAllMocks();
  useBuilder.mockReturnValue({ courseId: "course-1" });
});

/**
 * QuizLessonPanel calls `useQueryClient()` directly to invalidate the curriculum after attaching,
 * so unlike AssessmentBuilder — whose data hooks are all mocked — it needs a real client in scope.
 * Retries are off so a rejected mutation surfaces its error immediately instead of backing off.
 */
function renderPanel(ui: ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });

  return renderWithI18n(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe("QuizLessonPanel", () => {
  it("loads the assessment already attached to the lesson", () => {
    useAssessment.mockReturnValue({ data: assessment([question()]), isPending: false, isError: false, refetch: vi.fn() });
    useAssessmentActions.mockReturnValue(noopActions());

    renderPanel(
      <QuizLessonPanel
        block={quizBlock({
          assessment: { id: "a1", title: "Module quiz", status: "draft", question_count: 1, version: 1 },
        })}
      />,
    );

    expect(screen.getByText("Module quiz")).toBeInTheDocument();
    // The builder rendered, meaning it was handed the attached assessment's id.
    expect(useAssessment).toHaveBeenCalledWith("a1");
  });

  it("creates an assessment and attaches it when the lesson has none", async () => {
    const user = userEvent.setup();
    createAssessment.mockResolvedValue({ id: "a-new" });
    setLessonAssessment.mockResolvedValue(undefined);
    useAssessment.mockReturnValue({ data: undefined, isPending: true, isError: false, refetch: vi.fn() });
    useAssessmentActions.mockReturnValue(noopActions());

    renderPanel(<QuizLessonPanel block={quizBlock()} />);

    expect(screen.getByText("This lesson has no quiz yet.")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Create a quiz" }));

    await waitFor(() => {
      // Two calls, in order: the assessment is created on the COURSE, then the lesson is pointed
      // at it. Creating it against the lesson would make it un-reusable.
      expect(createAssessment).toHaveBeenCalledWith("course-1", { title: "Knowledge check" });
      expect(setLessonAssessment).toHaveBeenCalledWith("l1", "a-new");
    });
  });

  it("surfaces a server refusal instead of failing silently", async () => {
    const user = userEvent.setup();
    createAssessment.mockRejectedValue(new Error("Course not found."));
    useAssessment.mockReturnValue({ data: undefined, isPending: true, isError: false, refetch: vi.fn() });
    useAssessmentActions.mockReturnValue(noopActions());

    renderPanel(<QuizLessonPanel block={quizBlock()} />);
    await user.click(screen.getByRole("button", { name: "Create a quiz" }));

    // The 404 an instructor gets for a course they do not train must reach them verbatim.
    expect(await screen.findByRole("alert")).toHaveTextContent("Course not found.");
    expect(setLessonAssessment).not.toHaveBeenCalled();
  });
});

describe("AssessmentBuilder question list", () => {
  it("shows the loading state while the assessment resolves", () => {
    useAssessment.mockReturnValue({ data: undefined, isPending: true, isError: false, refetch: vi.fn() });
    useAssessmentActions.mockReturnValue(noopActions());

    renderWithI18n(<AssessmentBuilder assessmentId="a1" />);

    expect(screen.getByRole("status")).toBeInTheDocument();
  });

  it("shows an error state with a retry when loading fails", async () => {
    const user = userEvent.setup();
    const refetch = vi.fn();
    useAssessment.mockReturnValue({ data: undefined, isPending: false, isError: true, refetch });
    useAssessmentActions.mockReturnValue(noopActions());

    renderWithI18n(<AssessmentBuilder assessmentId="a1" />);
    await user.click(screen.getByRole("button", { name: "Retry" }));

    expect(refetch).toHaveBeenCalled();
  });

  it("shows the empty state when the assessment has no questions", () => {
    useAssessment.mockReturnValue({ data: assessment([]), isPending: false, isError: false, refetch: vi.fn() });
    useAssessmentActions.mockReturnValue(noopActions());

    renderWithI18n(<AssessmentBuilder assessmentId="a1" />);

    expect(screen.getAllByText("No questions yet. Add one to get started.").length).toBeGreaterThan(0);
  });

  it("lists questions numbered by position and totals their points", () => {
    useAssessment.mockReturnValue({
      data: assessment([
        question({ id: "q1", prompt: "<p>First</p>", points: 2 }),
        question({ id: "q2", prompt: "<p>Second</p>", points: 3, position: 1 }),
      ]),
      isPending: false,
      isError: false,
      refetch: vi.fn(),
    });
    useAssessmentActions.mockReturnValue(noopActions());

    renderWithI18n(<AssessmentBuilder assessmentId="a1" />);

    expect(screen.getByText("Q1")).toBeInTheDocument();
    expect(screen.getByText("Q2")).toBeInTheDocument();
    expect(screen.getByText("5 points total")).toBeInTheDocument();
  });

  it("offers every V1 question type and creates the chosen one", async () => {
    const user = userEvent.setup();
    const actions = noopActions();
    useAssessment.mockReturnValue({ data: assessment([]), isPending: false, isError: false, refetch: vi.fn() });
    useAssessmentActions.mockReturnValue(actions);

    renderWithI18n(<AssessmentBuilder assessmentId="a1" />);
    await user.click(screen.getByRole("button", { name: "Add question" }));

    for (const label of ["Single choice", "Multiple choice", "True / False", "Short answer", "Fill in the blank"]) {
      expect(screen.getByRole("menuitem", { name: label })).toBeInTheDocument();
    }

    await user.click(screen.getByRole("menuitem", { name: "True / False" }));

    await waitFor(() => {
      const payload = actions.addQuestion.mock.calls[0][0];
      expect(payload.type).toBe("true_false");
      // True/False must arrive with its two options already set — the type mandates them, and a
      // question the shape guard would reject should never be created in the first place.
      expect(payload.options).toHaveLength(2);
    });
  });

  it("filters the list by search without renumbering the remaining questions", async () => {
    const user = userEvent.setup();
    useAssessment.mockReturnValue({
      data: assessment([
        question({ id: "q1", prompt: "<p>Photosynthesis</p>" }),
        question({ id: "q2", prompt: "<p>Respiration</p>", position: 1 }),
      ]),
      isPending: false,
      isError: false,
      refetch: vi.fn(),
    });
    useAssessmentActions.mockReturnValue(noopActions());

    renderWithI18n(<AssessmentBuilder assessmentId="a1" />);
    await user.type(screen.getByLabelText("Search questions"), "respir");

    // Q2 keeps its number even though it is now the only row — the number is its position in the
    // real list, which is what the learner will see.
    expect(screen.getByText("Q2")).toBeInTheDocument();
    expect(screen.queryByText("Q1")).not.toBeInTheDocument();
  });

  it("deletes only after the confirmation is accepted", async () => {
    const user = userEvent.setup();
    const actions = noopActions();
    useAssessment.mockReturnValue({
      data: assessment([question({ id: "q1", prompt: "<p>First</p>" })]),
      isPending: false,
      isError: false,
      refetch: vi.fn(),
    });
    useAssessmentActions.mockReturnValue(actions);

    renderWithI18n(<AssessmentBuilder assessmentId="a1" />);
    await user.click(screen.getByRole("button", { name: "Delete" }));

    // The dialog is open; nothing has been deleted yet.
    expect(actions.removeQuestion).not.toHaveBeenCalled();

    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Delete" }));

    await waitFor(() => expect(actions.removeQuestion).toHaveBeenCalledWith("q1"));
  });

  it("surfaces an authorization failure from a mutation", async () => {
    const user = userEvent.setup();
    const actions = noopActions();
    actions.addQuestion.mockRejectedValue(new Error("This action is unauthorized."));
    useAssessment.mockReturnValue({ data: assessment([]), isPending: false, isError: false, refetch: vi.fn() });
    useAssessmentActions.mockReturnValue(actions);

    renderWithI18n(<AssessmentBuilder assessmentId="a1" />);
    await user.click(screen.getByRole("button", { name: "Add question" }));
    await user.click(screen.getByRole("menuitem", { name: "Single choice" }));

    // Without this the optimistic state simply rolls back and the author sees nothing happen.
    expect(await screen.findByRole("alert")).toHaveTextContent("This action is unauthorized.");
  });

  it("exposes each question's drag handle to keyboard users", () => {
    useAssessment.mockReturnValue({
      data: assessment([question({ id: "q1", prompt: "<p>First</p>" })]),
      isPending: false,
      isError: false,
      refetch: vi.fn(),
    });
    useAssessmentActions.mockReturnValue(noopActions());

    renderWithI18n(<AssessmentBuilder assessmentId="a1" />);

    // dnd-kit's keyboard sensor only works if the handle is a focusable, labelled control.
    const handle = screen.getByRole("button", { name: "Q1: First" });
    expect(handle).toBeInTheDocument();
    handle.focus();
    expect(handle).toHaveFocus();
  });
});
