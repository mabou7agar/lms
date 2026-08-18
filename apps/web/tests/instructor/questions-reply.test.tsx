import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

/*
 | The instructor queue showed who was waiting and for how long, but had no way to answer them —
 | the one action the screen exists for. A reply must also move the question out of "awaiting" and
 | refresh the SLA metrics, which is why answering invalidates the thread, the course list AND the
 | queue rather than only the thread.
 */

const { useInstructorQueue, useQuestion, answerMutate, officialMutate, toastError, toastSuccess } = vi.hoisted(() => ({
  useInstructorQueue: vi.fn(),
  useQuestion: vi.fn(),
  answerMutate: vi.fn(),
  officialMutate: vi.fn(),
  toastError: vi.fn(),
  toastSuccess: vi.fn(),
}));

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }), usePathname: () => "/teach/questions" }));
vi.mock("@/lib/courseware/hooks", () => ({
  useInstructorQueue,
  useQuestion,
  useAnswerQuestion: () => ({ mutate: answerMutate, isPending: false }),
  useMarkAnswerOfficial: () => ({ mutate: officialMutate, isPending: false }),
}));
vi.mock("@/components/ui/toast", () => ({ toast: { error: toastError, success: toastSuccess } }));

import InstructorQuestionsPage from "@/app/(instructor)/teach/questions/page";
import { instructorNav } from "@/config/nav";

const ok = (data: unknown) => ({ isPending: false, isError: false, refetch: vi.fn(), data });

const question = {
  id: "q1",
  title: "How do I apply this to a resistant team?",
  body: "Two people keep skipping the review step.",
  is_private: false,
  awaiting_response: true,
  answers_count: 0,
  first_response_at: null,
  first_response_minutes: null,
  author: { name: "QA Student One" },
};

const metrics = {
  questions: 2,
  answered: 1,
  unanswered: 1,
  overdue: 0,
  response_rate: 0.5,
  avg_first_response_minutes: 90,
  median_first_response_minutes: 90,
  sla_hours: 24,
};

beforeEach(() => {
  vi.clearAllMocks();
  useInstructorQueue.mockReturnValue(ok({ metrics, questions: [question], meta: { total: 1 } }));
  useQuestion.mockReturnValue(ok({ ...question, answers: [] }));
});

describe("instructor Q&A queue", () => {
  it("is reachable from the instructor navigation", () => {
    expect(instructorNav.some((item) => item.href === "/teach/questions")).toBe(true);
  });

  it("shows an unanswered question as awaiting a response", () => {
    renderWithI18n(<InstructorQuestionsPage />);
    expect(screen.getByText(question.title)).toBeInTheDocument();
    expect(screen.getByText(/awaiting/i)).toBeInTheDocument();
  });

  it("opens the thread and lets the instructor post an answer", async () => {
    renderWithI18n(<InstructorQuestionsPage />);

    await userEvent.click(screen.getByTestId("queue-open-q1"));
    expect(screen.getByTestId("queue-thread-q1")).toBeInTheDocument();

    await userEvent.type(screen.getByLabelText(/your answer/i), "Make the review step smaller than the work it protects.");
    await userEvent.click(screen.getByTestId("queue-reply-q1"));

    expect(answerMutate).toHaveBeenCalledWith(
      { questionId: "q1", body: "Make the review step smaller than the work it protects." },
      expect.anything(),
    );
  });

  it("refuses an empty reply rather than posting a blank answer", async () => {
    renderWithI18n(<InstructorQuestionsPage />);

    await userEvent.click(screen.getByTestId("queue-open-q1"));
    await userEvent.click(screen.getByTestId("queue-reply-q1"));

    expect(answerMutate).not.toHaveBeenCalled();
    expect(toastError).toHaveBeenCalled();
  });

  it("clears the box and confirms once the answer is posted", async () => {
    answerMutate.mockImplementation((_vars, opts) => opts.onSuccess());
    renderWithI18n(<InstructorQuestionsPage />);

    await userEvent.click(screen.getByTestId("queue-open-q1"));
    const box = screen.getByLabelText(/your answer/i);
    await userEvent.type(box, "Answered.");
    await userEvent.click(screen.getByTestId("queue-reply-q1"));

    await waitFor(() => expect(toastSuccess).toHaveBeenCalled());
    expect(box).toHaveValue("");
  });

  it("keeps the instructor/official/accepted flags visible on an existing answer", async () => {
    useQuestion.mockReturnValue(
      ok({
        ...question,
        answers: [
          {
            id: "a1",
            body: "Start with a five-minute checklist.",
            is_instructor: true,
            is_official: true,
            accepted: true,
            author: { name: "Yara Adel" },
          },
        ],
      }),
    );

    renderWithI18n(<InstructorQuestionsPage />);
    await userEvent.click(screen.getByTestId("queue-open-q1"));

    expect(screen.getByText(/official/i)).toBeInTheDocument();
    expect(screen.getByText(/instructor/i)).toBeInTheDocument();
    expect(screen.getByText(/accepted/i)).toBeInTheDocument();
  });

  it("offers to promote an instructor answer that is not yet official", async () => {
    useQuestion.mockReturnValue(
      ok({
        ...question,
        answers: [
          {
            id: "a1",
            body: "Try a checklist.",
            is_instructor: true,
            is_official: false,
            accepted: false,
            author: { name: "Yara Adel" },
          },
        ],
      }),
    );

    renderWithI18n(<InstructorQuestionsPage />);
    await userEvent.click(screen.getByTestId("queue-open-q1"));
    await userEvent.click(screen.getByRole("button", { name: /mark as official/i }));

    expect(officialMutate).toHaveBeenCalledWith({ answerId: "a1", questionId: "q1" }, expect.anything());
  });

  it("surfaces the SLA metrics the reply is meant to move", () => {
    renderWithI18n(<InstructorQuestionsPage />);
    // unanswered count from the queue metrics
    expect(screen.getByText("1")).toBeInTheDocument();
  });
});
