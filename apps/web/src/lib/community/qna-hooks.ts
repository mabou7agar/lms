"use client";

/**
 * Course Q&A — React Query data controller.
 *
 * Mirrors `lib/catalog/hooks.ts`: list + detail queries, mutations with plain invalidation on
 * success. View state (which question is open, filters) stays in the components; this layer is data
 * only.
 */
import { useMutation, useQuery, useQueryClient, type QueryKey } from "@tanstack/react-query";
import {
  acceptAnswer,
  answerQuestion,
  askQuestion,
  getQuestion,
  listQuestions,
  reportAnswer,
  reportQuestion,
  type AnswerInput,
  type AskQuestionInput,
  type QuestionListParams,
  type QuestionSort,
  type QuestionStatus,
} from "./qna-api";
import type { ReportInput } from "./reviews-api";

export function questionsKey(course: string): QueryKey {
  return ["community", "questions", course];
}
export function questionKey(question: string): QueryKey {
  return ["community", "question", question];
}

export function useQuestions(
  course: string,
  opts: { sort?: QuestionSort; status?: QuestionStatus; lessonId?: string; page?: number } = {},
) {
  const params: QuestionListParams = {
    sort: opts.sort,
    status: opts.status,
    lesson_id: opts.lessonId,
    page: opts.page,
  };
  return useQuery({
    queryKey: [...questionsKey(course), params],
    queryFn: () => listQuestions(course, params),
    enabled: Boolean(course),
    placeholderData: (previous) => previous,
    staleTime: 15_000,
  });
}

export function useQuestion(question: string | null) {
  return useQuery({
    queryKey: questionKey(question ?? "none"),
    queryFn: () => getQuestion(question as string),
    enabled: Boolean(question),
    staleTime: 10_000,
  });
}

export function useAskQuestion(course: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: AskQuestionInput) => askQuestion(course, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: questionsKey(course) }),
  });
}

export function useAnswerQuestion(course: string, question: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: AnswerInput) => answerQuestion(question, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: questionKey(question) });
      qc.invalidateQueries({ queryKey: questionsKey(course) });
    },
  });
}

export function useAcceptAnswer(course: string, question: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (answer: string) => acceptAnswer(answer),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: questionKey(question) });
      qc.invalidateQueries({ queryKey: questionsKey(course) });
    },
  });
}

export function useReportQuestion() {
  return useMutation({
    mutationFn: (args: { question: string; input: ReportInput }) => reportQuestion(args.question, args.input),
  });
}

export function useReportAnswer() {
  return useMutation({
    mutationFn: (args: { answer: string; input: ReportInput }) => reportAnswer(args.answer, args.input),
  });
}
