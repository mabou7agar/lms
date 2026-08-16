"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  acceptAnswer,
  answerQuestion,
  askQuestion,
  closeQuestion,
  downloadResource,
  getCourseQuestions,
  getCourseResources,
  getInstructorQueue,
  getLessonResources,
  getQuestion,
  markAnswerOfficial,
  type QuestionFilters,
} from "./api";

/**
 * React-Query hooks for course files and Q&A. Thin `useQuery` wrappers with stable keys, and
 * mutations that invalidate exactly what the write actually changed.
 *
 * Downloads are a mutation with NO cache entry on purpose: the URL they return is short-lived and
 * re-authorized on every request, so caching one would hand back a link that has already died — or,
 * worse, one that outlived the entitlement behind it.
 */

const KEYS = {
  courseResources: (courseId: string, scope: string) => ["courseware", "resources", courseId, scope] as const,
  lessonResources: (lessonId: string) => ["courseware", "lesson-resources", lessonId] as const,
  questions: (courseId: string, filters: QuestionFilters) =>
    ["courseware", "questions", courseId, filters] as const,
  question: (questionId: string) => ["courseware", "question", questionId] as const,
  instructorQueue: (filter: string) => ["courseware", "instructor-queue", filter] as const,
};

// ── Resources ─────────────────────────────────────────────────────────────────────────────────────

export const useCourseResources = (courseId: string | null, scope: "course" | "all" = "course") =>
  useQuery({
    queryKey: KEYS.courseResources(courseId ?? "", scope),
    queryFn: () => getCourseResources(courseId as string, scope),
    enabled: !!courseId,
  });

export const useLessonResources = (lessonId: string | null) =>
  useQuery({
    queryKey: KEYS.lessonResources(lessonId ?? ""),
    queryFn: () => getLessonResources(lessonId as string),
    enabled: !!lessonId,
  });

export const useDownloadResource = () =>
  useMutation({ mutationFn: (resourceId: string) => downloadResource(resourceId) });

// ── Q&A ───────────────────────────────────────────────────────────────────────────────────────────

export const useCourseQuestions = (courseId: string | null, filters: QuestionFilters = {}) =>
  useQuery({
    queryKey: KEYS.questions(courseId ?? "", filters),
    queryFn: () => getCourseQuestions(courseId as string, filters),
    enabled: !!courseId,
  });

export const useQuestion = (questionId: string | null) =>
  useQuery({
    queryKey: KEYS.question(questionId ?? ""),
    queryFn: () => getQuestion(questionId as string),
    enabled: !!questionId,
  });

export const useInstructorQueue = (filter: "all" | "unanswered" | "overdue" | "answered" = "unanswered") =>
  useQuery({ queryKey: KEYS.instructorQueue(filter), queryFn: () => getInstructorQueue(filter) });

/** Asking adds a thread to the course list; nothing else changes. */
export function useAskQuestion(courseId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: Parameters<typeof askQuestion>[1]) => askQuestion(courseId, body),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["courseware", "questions", courseId] }),
  });
}

/**
 * Answering changes the thread, the course list (its status and answer count) AND the instructor's
 * queue and metrics — an instructor's first reply is what stops a question being overdue.
 */
export function useAnswerQuestion() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ questionId, body }: { questionId: string; body: string }) =>
      answerQuestion(questionId, body),
    onSuccess: (_data, { questionId }) => {
      qc.invalidateQueries({ queryKey: KEYS.question(questionId) });
      qc.invalidateQueries({ queryKey: ["courseware", "questions"] });
      qc.invalidateQueries({ queryKey: ["courseware", "instructor-queue"] });
    },
  });
}

function useThreadInvalidation() {
  const qc = useQueryClient();
  return (questionId?: string) => {
    if (questionId) qc.invalidateQueries({ queryKey: KEYS.question(questionId) });
    qc.invalidateQueries({ queryKey: ["courseware", "questions"] });
    qc.invalidateQueries({ queryKey: ["courseware", "instructor-queue"] });
  };
}

export function useAcceptAnswer() {
  const invalidate = useThreadInvalidation();
  return useMutation({
    mutationFn: ({ answerId }: { answerId: string; questionId?: string }) => acceptAnswer(answerId),
    onSuccess: (_d, { questionId }) => invalidate(questionId),
  });
}

export function useMarkAnswerOfficial() {
  const invalidate = useThreadInvalidation();
  return useMutation({
    mutationFn: ({ answerId }: { answerId: string; questionId?: string }) => markAnswerOfficial(answerId),
    onSuccess: (_d, { questionId }) => invalidate(questionId),
  });
}

export function useCloseQuestion() {
  const invalidate = useThreadInvalidation();
  return useMutation({
    mutationFn: (questionId: string) => closeQuestion(questionId),
    onSuccess: (_d, questionId) => invalidate(questionId),
  });
}
