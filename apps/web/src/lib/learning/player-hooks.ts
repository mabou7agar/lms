'use client';

/**
 * React Query hooks for the learner course player. Mirrors the per-domain
 * lib pattern (versioning-api + versioning-hooks): thin wrappers over
 * player-api.ts with stable query keys and cache invalidation on writes.
 *
 * Progress is server-authoritative: completion state always comes back from the
 * mutation response and we refetch curriculum/summary rather than mutating cache
 * optimistically for completion.
 */
import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationResult,
  type UseQueryResult,
} from '@tanstack/react-query';

import {
  completeBlock,
  completeLesson,
  fetchCurriculum,
  fetchLessonContent,
  fetchLessonPlayback,
  fetchProgressSummary,
  fetchResume,
  launchCourse,
  markLessonViewed,
  recordVideoProgress,
  type BlockCompleteResult,
  type CourseLaunch,
  type LessonCompleteResult,
  type LessonContent,
  type LessonViewedResult,
  type PlaybackTicket,
  type ProgressSummary,
  type RecordVideoProgressBody,
  type ResumePointer,
  type RuntimeCurriculum,
  type VideoProgress,
} from './player-api';

export const learningPlayerKeys = {
  all: ['learning', 'player'] as const,
  course: (courseId: string) => [...learningPlayerKeys.all, 'course', courseId] as const,
  curriculum: (courseId: string) =>
    [...learningPlayerKeys.course(courseId), 'curriculum'] as const,
  summary: (courseId: string) => [...learningPlayerKeys.course(courseId), 'summary'] as const,
  resume: (courseId: string) => [...learningPlayerKeys.course(courseId), 'resume'] as const,
  lesson: (lessonId: string) => [...learningPlayerKeys.all, 'lesson', lessonId] as const,
  lessonContent: (lessonId: string) => [...learningPlayerKeys.lesson(lessonId), 'content'] as const,
  playback: (lessonId: string) => [...learningPlayerKeys.lesson(lessonId), 'playback'] as const,
};

// ---------------------------------------------------------------------------
// Queries
// ---------------------------------------------------------------------------

export function useCurriculum(
  courseId: string,
  options?: { enabled?: boolean },
): UseQueryResult<RuntimeCurriculum> {
  return useQuery({
    queryKey: learningPlayerKeys.curriculum(courseId),
    queryFn: () => fetchCurriculum(courseId),
    enabled: (options?.enabled ?? true) && Boolean(courseId),
  });
}

export function useProgressSummary(
  courseId: string,
  options?: { enabled?: boolean },
): UseQueryResult<ProgressSummary> {
  return useQuery({
    queryKey: learningPlayerKeys.summary(courseId),
    queryFn: () => fetchProgressSummary(courseId),
    enabled: (options?.enabled ?? true) && Boolean(courseId),
  });
}

export function useResumePointer(
  courseId: string,
  options?: { enabled?: boolean },
): UseQueryResult<ResumePointer> {
  return useQuery({
    queryKey: learningPlayerKeys.resume(courseId),
    queryFn: () => fetchResume(courseId),
    enabled: (options?.enabled ?? true) && Boolean(courseId),
  });
}

export function useLessonContent(
  lessonId: string | null | undefined,
  options?: { enabled?: boolean },
): UseQueryResult<LessonContent> {
  return useQuery({
    queryKey: learningPlayerKeys.lessonContent(lessonId ?? ''),
    queryFn: () => fetchLessonContent(lessonId as string),
    enabled: (options?.enabled ?? true) && Boolean(lessonId),
  });
}

/**
 * JIT signed playback. Kept short-lived (staleTime 0) so a re-mount re-signs,
 * and never retried into a loop on expiry — the video player calls `refetch()`
 * when it observes an expired/403 URL.
 */
export function useLessonPlayback(
  lessonId: string | null | undefined,
  options?: { enabled?: boolean },
): UseQueryResult<PlaybackTicket> {
  return useQuery({
    queryKey: learningPlayerKeys.playback(lessonId ?? ''),
    queryFn: () => fetchLessonPlayback(lessonId as string),
    enabled: (options?.enabled ?? true) && Boolean(lessonId),
    staleTime: 0,
    gcTime: 0,
    retry: 1,
  });
}

// ---------------------------------------------------------------------------
// Mutations
// ---------------------------------------------------------------------------

export function useLaunchCourse(): UseMutationResult<CourseLaunch, unknown, string> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (courseId: string) => launchCourse(courseId),
    onSuccess: (_data, courseId) => {
      void qc.invalidateQueries({ queryKey: learningPlayerKeys.course(courseId) });
    },
  });
}

export function useMarkLessonViewed(
  courseId: string,
): UseMutationResult<LessonViewedResult, unknown, string> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (lessonId: string) => markLessonViewed(lessonId),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: learningPlayerKeys.resume(courseId) });
    },
  });
}

export function useCompleteLesson(
  courseId: string,
): UseMutationResult<LessonCompleteResult, unknown, string> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (lessonId: string) => completeLesson(lessonId),
    onSuccess: () => {
      // Completion is server-authoritative; refetch the gated views.
      void qc.invalidateQueries({ queryKey: learningPlayerKeys.curriculum(courseId) });
      void qc.invalidateQueries({ queryKey: learningPlayerKeys.summary(courseId) });
      void qc.invalidateQueries({ queryKey: learningPlayerKeys.resume(courseId) });
    },
  });
}

export function useCompleteBlock(
  courseId: string,
): UseMutationResult<BlockCompleteResult, unknown, { lessonId: string; blockRef: string }> {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ lessonId, blockRef }) => completeBlock(lessonId, blockRef),
    onSuccess: (_res, { lessonId }) => {
      void qc.invalidateQueries({ queryKey: learningPlayerKeys.lessonContent(lessonId) });
      void qc.invalidateQueries({ queryKey: learningPlayerKeys.curriculum(courseId) });
    },
  });
}

/**
 * Low-level video heartbeat mutation. Prefer wiring the VideoProgressClient
 * (progress-client.ts) with `mutateAsync` as its transport so writes are
 * throttled/batched — do NOT call this per animation frame.
 */
export function useRecordVideoProgress(
  lessonId: string,
): UseMutationResult<VideoProgress, unknown, RecordVideoProgressBody> {
  return useMutation({
    mutationFn: (body: RecordVideoProgressBody) => recordVideoProgress(lessonId, body),
  });
}
