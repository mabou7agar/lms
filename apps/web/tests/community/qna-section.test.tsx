import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useQuestions, useQuestion, askMutate, answerMutate, acceptMutate } = vi.hoisted(() => ({
  useQuestions: vi.fn(),
  useQuestion: vi.fn(),
  askMutate: vi.fn(),
  answerMutate: vi.fn(),
  acceptMutate: vi.fn(),
}));

vi.mock("@/lib/auth/auth-context", () => ({ useAuth: () => ({ status: "authenticated", user: { id: "u1" } }) }));
vi.mock("@/lib/community/qna-hooks", () => ({
  useQuestions,
  useQuestion,
  useAskQuestion: () => ({ mutate: askMutate, isPending: false }),
  useAnswerQuestion: () => ({ mutate: answerMutate, isPending: false }),
  useAcceptAnswer: () => ({ mutate: acceptMutate, isPending: false }),
  useReportQuestion: () => ({ mutateAsync: vi.fn(() => Promise.resolve()), isPending: false }),
  useReportAnswer: () => ({ mutateAsync: vi.fn(() => Promise.resolve()), isPending: false }),
}));

import { QnaSection } from "@/components/community/qna-section";

const listResult = () => ({
  isPending: false,
  isError: false,
  refetch: vi.fn(),
  data: {
    data: [
      {
        id: "q1",
        title: "How do I reset progress?",
        body: "Details here",
        status: "open",
        pinned: true,
        pinned_at: "2026-01-01T00:00:00Z",
        lesson_timestamp_seconds: null,
        answers_count: 1,
        is_resolved: false,
        author: { id: "u2", name: "Sara" },
        created_at: null,
        updated_at: null,
      },
    ],
    meta: { current_page: 1, per_page: 15, total: 1, last_page: 1 },
    links: { first: null, last: null, prev: null, next: null },
  },
});

const detailResult = () => ({
  isPending: false,
  isError: false,
  refetch: vi.fn(),
  data: {
    id: "q1",
    title: "How do I reset progress?",
    body: "Details here",
    status: "open",
    pinned: true,
    pinned_at: "2026-01-01T00:00:00Z",
    lesson_timestamp_seconds: 90,
    answers_count: 1,
    accepted_answer_id: null,
    is_resolved: false,
    author: { id: "u1", name: "You" },
    created_at: null,
    updated_at: null,
    answers: [
      { id: "a1", body: "Go to settings", is_instructor: true, accepted: false, author: { id: "u3", name: "Coach" }, created_at: null, updated_at: null },
    ],
  },
});

describe("QnaSection", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the question list with pinned + answer count", () => {
    useQuestions.mockReturnValue(listResult());
    renderWithI18n(<QnaSection courseId="c1" />);

    expect(screen.getByText("How do I reset progress?")).toBeInTheDocument();
    expect(screen.getByText(/Pinned/i)).toBeInTheDocument();
    expect(screen.getByText(/1 answer/i)).toBeInTheDocument();
  });

  it("posts a new question", async () => {
    useQuestions.mockReturnValue(listResult());
    renderWithI18n(<QnaSection courseId="c1" />);

    await userEvent.click(screen.getByRole("button", { name: "Ask a question" }));
    await userEvent.type(screen.getByLabelText("Question"), "My question");
    await userEvent.type(screen.getByLabelText("Details"), "Some detail");
    await userEvent.click(screen.getByRole("button", { name: "Post question" }));

    expect(askMutate).toHaveBeenCalledWith({ title: "My question", body: "Some detail" }, expect.anything());
  });

  it("opens a question thread and lets the author accept an answer", async () => {
    useQuestions.mockReturnValue(listResult());
    useQuestion.mockReturnValue(detailResult());
    renderWithI18n(<QnaSection courseId="c1" />);

    await userEvent.click(screen.getByRole("button", { name: /How do I reset progress/i }));
    expect(screen.getByText("Go to settings")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /Mark as best answer/i }));
    expect(acceptMutate).toHaveBeenCalledWith("a1", expect.anything());
  });
});
