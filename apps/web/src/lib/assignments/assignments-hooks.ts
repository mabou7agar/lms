"use client";

/**
 * Assignments — React Query data controller (W04, HELBARON LMS).
 *
 * ┌───────────────────────────────────────────────────────────────────────────────────────────┐
 * │ SHARED CONTRACT — this hook surface is imported by D4 (submission/grading) and D5.           │
 * │ Names + signatures below are STABLE. Add hooks; do not rename or repurpose existing ones.    │
 * │                                                                                              │
 * │ Query keys (exported):                                                                       │
 * │   courseAssignmentsKey(courseId)      assignmentKey(assignmentId)                            │
 * │   assignmentSubmissionsKey(assignmentId)   submissionKey(submissionId)                       │
 * │   learnerAssignmentKey(assignmentId)  submissionHistoryKey(assignmentId)                     │
 * │   learnerSubmissionKey(submissionId)  gradebookKey(courseId)                                 │
 * │                                                                                              │
 * │ Authoring (instructor):                                                                      │
 * │   useCourseAssignments(courseId, page?)   useAssignment(assignmentId)                        │
 * │   useCreateAssignment(courseId)           useUpdateAssignment(assignmentId)                  │
 * │   useDeleteAssignment(assignmentId, courseId)                                                │
 * │   usePublishAssignment(assignmentId)      useUnpublishAssignment(assignmentId)               │
 * │   useBuildRubric(assignmentId)                                                               │
 * │                                                                                              │
 * │ Grading (instructor) — D4:                                                                   │
 * │   useGradingQueue(assignmentId, query?)   useInstructorSubmission(submissionId)              │
 * │   useGradeSubmission(submissionId)        useRequestChanges(submissionId)                    │
 * │   useReleaseGrade(submissionId)           useUnreleaseGrade(submissionId)                    │
 * │   useGradebook(courseId, query?)                                                             │
 * │   (aliases: useSubmissions, useSubmission)                                                   │
 * │                                                                                              │
 * │ Submission (learner) — D4:                                                                   │
 * │   useLearnerAssignment(assignmentId)      useSubmissionHistory(assignmentId)                 │
 * │   useLearnerSubmission(submissionId)                                                         │
 * │   useSaveDraft(assignmentId)              useAttachDraftFile(assignmentId)                   │
 * │   useDetachDraftFile(assignmentId?)       useSubmitAssignment(assignmentId)                  │
 * │   useResubmitAssignment(assignmentId)     (aliases: useAttachFile, useDetachFile)            │
 * └───────────────────────────────────────────────────────────────────────────────────────────┘
 *
 * Mirrors `lib/authoring/versioning-hooks.ts`: queries + mutations, plain invalidation on success
 * (no optimism for anything the server can reject — publish, grade, submit). View state stays in the
 * components; this layer is data only.
 */

import { useMutation, useQuery, useQueryClient, type QueryKey } from "@tanstack/react-query";
import * as client from "./assignments-api";
import type {
  AssignmentInput,
  AttachFileInput,
  CreateAssignmentInput,
  GradeInput,
  RequestChangesInput,
  RubricInput,
  SaveDraftInput,
} from "./assignments-api";

// ─────────────────────────────────────────────────────────────────────────────
// Query keys (stable — D4 uses these to invalidate/read the shared cache)
// ─────────────────────────────────────────────────────────────────────────────

export function courseAssignmentsKey(courseId: string): QueryKey {
  return ["assignments", "course", courseId];
}
export function assignmentKey(assignmentId: string): QueryKey {
  return ["assignment", assignmentId];
}
export function assignmentSubmissionsKey(assignmentId: string): QueryKey {
  return ["assignment", assignmentId, "submissions"];
}
export function submissionKey(submissionId: string): QueryKey {
  return ["submission", submissionId];
}
export function learnerAssignmentKey(assignmentId: string): QueryKey {
  return ["learner-assignment", assignmentId];
}
export function submissionHistoryKey(assignmentId: string): QueryKey {
  return ["learner-assignment", assignmentId, "history"];
}
export function learnerSubmissionKey(submissionId: string): QueryKey {
  return ["learner-submission", submissionId];
}

// ─────────────────────────────────────────────────────────────────────────────
// Authoring queries
// ─────────────────────────────────────────────────────────────────────────────

export function useCourseAssignments(courseId: string, page = 1) {
  return useQuery({
    queryKey: [...courseAssignmentsKey(courseId), page],
    queryFn: () => client.listAssignments(courseId, page),
    enabled: Boolean(courseId),
    placeholderData: (previous) => previous,
    staleTime: 30_000,
  });
}

export function useAssignment(assignmentId: string | null) {
  return useQuery({
    queryKey: assignmentKey(assignmentId ?? "none"),
    queryFn: () => client.getAssignment(assignmentId as string),
    enabled: Boolean(assignmentId),
    staleTime: 15_000,
  });
}

// ─────────────────────────────────────────────────────────────────────────────
// Authoring mutations
// ─────────────────────────────────────────────────────────────────────────────

export function useCreateAssignment(courseId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: CreateAssignmentInput) => client.createAssignment(courseId, input),
    onSuccess: (created) => {
      qc.invalidateQueries({ queryKey: courseAssignmentsKey(courseId) });
      qc.setQueryData(assignmentKey(created.id), created);
    },
  });
}

export function useUpdateAssignment(assignmentId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: AssignmentInput) => client.updateAssignment(assignmentId, input),
    onSuccess: (updated) => {
      qc.setQueryData(assignmentKey(assignmentId), updated);
      qc.invalidateQueries({ queryKey: ["assignments", "course"] });
    },
  });
}

export function useDeleteAssignment(assignmentId: string, courseId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => client.deleteAssignment(assignmentId),
    onSuccess: () => {
      qc.removeQueries({ queryKey: assignmentKey(assignmentId) });
      qc.invalidateQueries({ queryKey: courseAssignmentsKey(courseId) });
    },
  });
}

export function usePublishAssignment(assignmentId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => client.publishAssignment(assignmentId),
    onSuccess: (updated) => {
      qc.setQueryData(assignmentKey(assignmentId), updated);
      qc.invalidateQueries({ queryKey: ["assignments", "course"] });
    },
  });
}

export function useUnpublishAssignment(assignmentId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => client.unpublishAssignment(assignmentId),
    onSuccess: (updated) => {
      qc.setQueryData(assignmentKey(assignmentId), updated);
      qc.invalidateQueries({ queryKey: ["assignments", "course"] });
    },
  });
}

/** Replaces the assignment's rubric wholesale, then refreshes the assignment (carries the rubric). */
export function useBuildRubric(assignmentId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: RubricInput) => client.buildRubric(assignmentId, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: assignmentKey(assignmentId) });
    },
  });
}

// ─────────────────────────────────────────────────────────────────────────────
// Grading queries + mutations (instructor) — imported by D4
// ─────────────────────────────────────────────────────────────────────────────

/** Query params for the grading queue (mirrors the paginated submissions endpoint). */
export interface SubmissionQuery {
  page?: number;
  per_page?: number;
  /** Reserved for a client-side triage filter; the submissions endpoint itself ignores it. */
  only?: "missing" | "late";
}

/**
 * The grading queue for an assignment. NOTE the (assignmentId, query) signature — D4's GradingQueue
 * calls `useGradingQueue(assignmentId, { page, per_page, only })`.
 */
export function useGradingQueue(assignmentId: string, query: SubmissionQuery = {}) {
  const page = query.page ?? 1;
  const perPage = query.per_page ?? 25;
  return useQuery({
    queryKey: [...assignmentSubmissionsKey(assignmentId), query],
    queryFn: () => client.listSubmissions(assignmentId, page, perPage),
    enabled: Boolean(assignmentId),
    placeholderData: (previous) => previous,
    staleTime: 10_000,
  });
}

/** @deprecated Prefer {@link useGradingQueue}. Kept for naming stability. */
export function useSubmissions(assignmentId: string, page = 1) {
  return useGradingQueue(assignmentId, { page });
}

/** Full grader view of one submission (private notes + unreleased score). */
export function useInstructorSubmission(submissionId: string | null) {
  return useQuery({
    queryKey: submissionKey(submissionId ?? "none"),
    queryFn: () => client.getSubmission(submissionId as string),
    enabled: Boolean(submissionId),
    staleTime: 5_000,
  });
}

/** @deprecated Alias of {@link useInstructorSubmission}. */
export const useSubmission = useInstructorSubmission;

/** Invalidate the graded submission + its queue + any gradebook (a grade changes all three). */
function useSubmissionInvalidation(submissionId: string) {
  const qc = useQueryClient();
  return () => {
    qc.invalidateQueries({ queryKey: submissionKey(submissionId) });
    qc.invalidateQueries({ queryKey: ["assignment"] });
    qc.invalidateQueries({ queryKey: ["gradebook"] });
  };
}

export function useGradeSubmission(submissionId: string) {
  const invalidate = useSubmissionInvalidation(submissionId);
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: GradeInput) => client.gradeSubmission(submissionId, input),
    onSuccess: (updated) => {
      qc.setQueryData(submissionKey(submissionId), updated);
      invalidate();
    },
  });
}

export function useRequestChanges(submissionId: string) {
  const invalidate = useSubmissionInvalidation(submissionId);
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: RequestChangesInput = {}) => client.requestChanges(submissionId, input),
    onSuccess: (updated) => {
      qc.setQueryData(submissionKey(submissionId), updated);
      invalidate();
    },
  });
}

export function useReleaseGrade(submissionId: string) {
  const invalidate = useSubmissionInvalidation(submissionId);
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => client.releaseGrade(submissionId),
    onSuccess: (updated) => {
      qc.setQueryData(submissionKey(submissionId), updated);
      invalidate();
    },
  });
}

export function useUnreleaseGrade(submissionId: string) {
  const invalidate = useSubmissionInvalidation(submissionId);
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => client.unreleaseGrade(submissionId),
    onSuccess: (updated) => {
      qc.setQueryData(submissionKey(submissionId), updated);
      invalidate();
    },
  });
}

// useGradebook (D3): REMOVED — use `useGradebook` from `@/lib/gradebook/gradebook-hooks` (D5).
// The grade mutations above still invalidate the shared ["gradebook"] key so D5's table refreshes.

// ─────────────────────────────────────────────────────────────────────────────
// Learner submission queries + mutations — imported by D4
// ─────────────────────────────────────────────────────────────────────────────

export function useLearnerAssignment(assignmentId: string | null) {
  return useQuery({
    queryKey: learnerAssignmentKey(assignmentId ?? "none"),
    queryFn: () => client.getLearnerAssignment(assignmentId as string),
    enabled: Boolean(assignmentId),
    staleTime: 15_000,
  });
}

export function useSubmissionHistory(assignmentId: string | null) {
  return useQuery({
    queryKey: submissionHistoryKey(assignmentId ?? "none"),
    queryFn: () => client.getSubmissionHistory(assignmentId as string),
    enabled: Boolean(assignmentId),
    staleTime: 5_000,
  });
}

export function useLearnerSubmission(submissionId: string | null) {
  return useQuery({
    queryKey: learnerSubmissionKey(submissionId ?? "none"),
    queryFn: () => client.getLearnerSubmission(submissionId as string),
    enabled: Boolean(submissionId),
    staleTime: 5_000,
  });
}

/** After any learner draft/submit change, refresh that assignment's history + the current draft. */
function useLearnerInvalidation(assignmentId: string) {
  const qc = useQueryClient();
  return (submission?: { id: string }) => {
    qc.invalidateQueries({ queryKey: submissionHistoryKey(assignmentId) });
    if (submission) qc.setQueryData(learnerSubmissionKey(submission.id), submission);
  };
}

export function useSaveDraft(assignmentId: string) {
  const invalidate = useLearnerInvalidation(assignmentId);
  return useMutation({
    mutationFn: (input: SaveDraftInput) => client.saveDraft(assignmentId, input),
    onSuccess: (submission) => invalidate(submission),
  });
}

export function useAttachDraftFile(assignmentId: string) {
  const invalidate = useLearnerInvalidation(assignmentId);
  return useMutation({
    mutationFn: (input: AttachFileInput) => client.attachFile(assignmentId, input),
    onSuccess: (submission) => invalidate(submission),
  });
}

/** @deprecated Alias of {@link useAttachDraftFile}. */
export const useAttachFile = useAttachDraftFile;

/**
 * Detach a file from a draft. `assignmentId` is optional — D4's panel calls `useDetachDraftFile()`
 * with no argument (it detaches by submission id, and the enclosing panel refetches the history it
 * owns). When an assignmentId is supplied, this hook also refreshes that assignment's history.
 */
export function useDetachDraftFile(assignmentId?: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (args: { submissionId: string; fileId: string }) =>
      client.detachFile(args.submissionId, args.fileId),
    onSuccess: (submission) => {
      qc.setQueryData(learnerSubmissionKey(submission.id), submission);
      if (assignmentId) qc.invalidateQueries({ queryKey: submissionHistoryKey(assignmentId) });
    },
  });
}

/** @deprecated Alias of {@link useDetachDraftFile}. */
export const useDetachFile = useDetachDraftFile;

export function useSubmitAssignment(assignmentId: string) {
  const invalidate = useLearnerInvalidation(assignmentId);
  return useMutation({
    mutationFn: () => client.submitAssignment(assignmentId),
    onSuccess: (submission) => invalidate(submission),
  });
}

export function useResubmitAssignment(assignmentId: string) {
  const invalidate = useLearnerInvalidation(assignmentId);
  return useMutation({
    mutationFn: () => client.resubmitAssignment(assignmentId),
    onSuccess: (submission) => invalidate(submission),
  });
}
