import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const {
  useCourseResources,
  useLessonResources,
  useCourseQuestions,
  useQuestion,
  useInstructorQueue,
  downloadMutate,
  askMutate,
  answerMutate,
} = vi.hoisted(() => ({
  useCourseResources: vi.fn(),
  useLessonResources: vi.fn(),
  useCourseQuestions: vi.fn(),
  useQuestion: vi.fn(),
  useInstructorQueue: vi.fn(),
  downloadMutate: vi.fn(),
  askMutate: vi.fn(),
  answerMutate: vi.fn(),
}));

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/courseware/hooks", () => ({
  useCourseResources,
  useLessonResources,
  useCourseQuestions,
  useQuestion,
  useInstructorQueue,
  useDownloadResource: () => ({ mutate: downloadMutate, isPending: false, variables: undefined }),
  useAskQuestion: () => ({ mutate: askMutate, isPending: false }),
  useAnswerQuestion: () => ({ mutate: answerMutate, isPending: false }),
  useAcceptAnswer: () => ({ mutate: vi.fn(), isPending: false }),
  useMarkAnswerOfficial: () => ({ mutate: vi.fn(), isPending: false }),
  useCloseQuestion: () => ({ mutate: vi.fn(), isPending: false }),
}));

import { ResourceList } from "@/components/courseware/resource-list";
import { QnaPanel } from "@/components/courseware/qna-panel";
import InstructorQuestionsPage from "@/app/(instructor)/teach/questions/page";

const ok = (data: unknown) => ({ isPending: false, isError: false, refetch: vi.fn(), data });

function resource(overrides: Record<string, unknown> = {}) {
  return {
    id: "res_1",
    title: "Course workbook",
    description: "Everything in one PDF.",
    visibility: "enrolled",
    downloadable: true,
    is_preview: false,
    scope: "course",
    position: 1,
    file: { mime_type: "application/pdf", size_bytes: 2_400_000 },
    created_at: null,
    ...overrides,
  };
}

function question(overrides: Record<string, unknown> = {}) {
  return {
    id: "q_1",
    title: "How does the discount work?",
    body: "I did not follow the worked example.",
    status: "open",
    pinned: false,
    pinned_at: null,
    lesson_timestamp_seconds: null,
    answers_count: 0,
    is_resolved: false,
    visibility: "public",
    is_private: false,
    first_response_at: null,
    first_response_minutes: null,
    awaiting_response: true,
    closed_at: null,
    author: { id: "u_1", name: "Nadia" },
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

describe("ResourceList", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useLessonResources.mockReturnValue(ok({ entitled: true, items: [] }));
  });

  it("lists a file with its kind and size", () => {
    useCourseResources.mockReturnValue(ok({ entitled: true, items: [resource()] }));

    renderWithI18n(<ResourceList courseId="c_1" />);

    expect(screen.getByText("Course workbook")).toBeInTheDocument();
    expect(screen.getByText(/PDF · 2\.3 MB/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Download/i })).toBeInTheDocument();
  });

  it("locks a file the viewer is not entitled to instead of offering a button that fails", () => {
    useCourseResources.mockReturnValue(ok({ entitled: false, items: [resource()] }));

    renderWithI18n(<ResourceList courseId="c_1" />);

    expect(screen.getByText(/Enrol to download/i)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Download/i })).not.toBeInTheDocument();
  });

  it("still offers a preview file to somebody who has not enrolled", () => {
    useCourseResources.mockReturnValue(
      ok({ entitled: false, items: [resource({ is_preview: true, visibility: "preview" })] }),
    );

    renderWithI18n(<ResourceList courseId="c_1" />);

    expect(screen.getByText(/Free sample/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Download/i })).toBeInTheDocument();
  });

  it("asks the server for a link rather than holding one", async () => {
    useCourseResources.mockReturnValue(ok({ entitled: true, items: [resource()] }));

    renderWithI18n(<ResourceList courseId="c_1" />);
    await userEvent.click(screen.getByRole("button", { name: /Download/i }));

    expect(downloadMutate).toHaveBeenCalledWith("res_1", expect.anything());
  });

  it("marks a view-only file as not downloadable", () => {
    useCourseResources.mockReturnValue(ok({ entitled: true, items: [resource({ downloadable: false })] }));

    renderWithI18n(<ResourceList courseId="c_1" />);

    expect(screen.getByText(/View only/i)).toBeInTheDocument();
  });
});

describe("QnaPanel", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useQuestion.mockReturnValue(ok({ ...question(), answers: [] }));
  });

  it("shows a waiting badge while nobody from the course has replied", () => {
    useCourseQuestions.mockReturnValue(ok([question()]));

    renderWithI18n(<QnaPanel courseId="c_1" />);

    expect(screen.getByText("How does the discount work?")).toBeInTheDocument();
    expect(screen.getByText(/Awaiting reply/i)).toBeInTheDocument();
  });

  it("marks a private thread so the asker can see it is not public", () => {
    useCourseQuestions.mockReturnValue(ok([question({ is_private: true, visibility: "private" })]));

    renderWithI18n(<QnaPanel courseId="c_1" />);

    expect(screen.getByText("Private")).toBeInTheDocument();
  });

  it("submits a question with the chosen visibility", async () => {
    useCourseQuestions.mockReturnValue(ok([]));

    renderWithI18n(<QnaPanel courseId="c_1" />);

    await userEvent.type(screen.getByLabelText("Question"), "Why is this?");
    await userEvent.type(screen.getByLabelText("Details"), "Because I am stuck.");
    await userEvent.click(screen.getByRole("button", { name: /^Post question$/i }));

    expect(askMutate).toHaveBeenCalledWith(
      { title: "Why is this?", body: "Because I am stuck.", lesson_id: null, visibility: "public" },
      expect.anything(),
    );
  });

  it("scopes to a lesson when the player passes one", async () => {
    useCourseQuestions.mockReturnValue(ok([]));

    renderWithI18n(<QnaPanel courseId="c_1" lessonId="l_1" />);

    expect(screen.getByText(/Questions about this lesson/i)).toBeInTheDocument();

    await userEvent.type(screen.getByLabelText("Question"), "Timecode?");
    await userEvent.type(screen.getByLabelText("Details"), "What is on screen.");
    await userEvent.click(screen.getByRole("button", { name: /^Post question$/i }));

    expect(askMutate).toHaveBeenCalledWith(
      expect.objectContaining({ lesson_id: "l_1" }),
      expect.anything(),
    );
  });

  it("shows an official answer distinctly from an accepted one", async () => {
    useCourseQuestions.mockReturnValue(ok([question({ answers_count: 1 })]));
    useQuestion.mockReturnValue(
      ok({
        ...question(),
        answers: [
          {
            id: "a_1",
            body: "Here is the answer.",
            is_instructor: true,
            accepted: false,
            is_official: true,
            author: { id: "u_2", name: "Instructor" },
            created_at: null,
            updated_at: null,
          },
        ],
      }),
    );

    renderWithI18n(<QnaPanel courseId="c_1" />);
    await userEvent.click(screen.getByRole("button", { name: /How does the discount work/i }));

    expect(screen.getByText("Official answer")).toBeInTheDocument();
    expect(screen.queryByText("Accepted")).not.toBeInTheDocument();
  });
});

describe("Instructor question inbox", () => {
  beforeEach(() => vi.clearAllMocks());

  const queue = (overrides: Record<string, unknown> = {}) =>
    ok({
      metrics: {
        questions: 12,
        answered: 9,
        unanswered: 3,
        overdue: 2,
        response_rate: 0.75,
        avg_first_response_minutes: 190,
        median_first_response_minutes: 95,
        sla_hours: 48,
        ...(overrides.metrics as object satisfies object),
      },
      questions: [question()],
      meta: { total: 1 },
      ...overrides,
    });

  it("leads with an overdue warning naming the promise that was missed", () => {
    useInstructorQueue.mockReturnValue(queue());

    renderWithI18n(<InstructorQuestionsPage />);

    expect(screen.getByText(/2 question\(s\) have waited longer than 48 hours/i)).toBeInTheDocument();
  });

  it("reports the response rate and both response averages", () => {
    useInstructorQueue.mockReturnValue(queue());

    renderWithI18n(<InstructorQuestionsPage />);

    expect(screen.getByText("75%")).toBeInTheDocument();
    // Median beside the mean: 95m reads as 1h 35m, 190m as 3h 10m.
    expect(screen.getByText("1h 35m")).toBeInTheDocument();
    expect(screen.getByText("3h 10m")).toBeInTheDocument();
  });

  it("stays quiet when nothing is overdue", () => {
    useInstructorQueue.mockReturnValue(queue({ metrics: { overdue: 0 } }));

    renderWithI18n(<InstructorQuestionsPage />);

    expect(screen.queryByText(/have waited longer than/i)).not.toBeInTheDocument();
  });
});
