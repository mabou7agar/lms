/**
 * Shared, non-test helpers for the course-player specs. Not a spec file.
 * Deterministic fixtures + query/mutation-result stubs so specs can mock the
 * player hooks without touching React Query or the API client.
 */
import { vi } from 'vitest';

import type {
  CourseLaunch,
  LessonContent,
  ProgressSummary,
  RuntimeCurriculum,
  RuntimeLesson,
} from '@/lib/learning/player-api';

export function lesson(overrides: Partial<RuntimeLesson> = {}): RuntimeLesson {
  return {
    id: 'lsn_1',
    title: 'Intro lesson',
    type: 'video',
    is_preview: false,
    has_media: true,
    completed: false,
    locked: false,
    lock_reason: null,
    prerequisites_met: true,
    released: true,
    available_at: null,
    estimated_duration_seconds: 300,
    ...overrides,
  };
}

export function curriculum(overrides: Partial<RuntimeCurriculum> = {}): RuntimeCurriculum {
  return {
    course: { id: 'crs_1', title: 'React Fundamentals', slug: 'react-fundamentals' },
    enrollment: { id: 'enr_1', status: 'active', progress_percentage: 25 },
    sections: [
      {
        id: 'sec_1',
        title: 'Getting started',
        lessons: [
          lesson({ id: 'lsn_1', title: 'Welcome', completed: true }),
          lesson({ id: 'lsn_2', title: 'Setup' }),
          lesson({
            id: 'lsn_3',
            title: 'Advanced (locked)',
            locked: true,
            lock_reason: 'prerequisite_incomplete',
            prerequisites_met: false,
          }),
        ],
      },
    ],
    ...overrides,
  };
}

export function progressSummary(overrides: Partial<ProgressSummary> = {}): ProgressSummary {
  return {
    course_id: 'crs_1',
    status: 'active',
    progress_percentage: 25,
    total_lessons: 3,
    completed_lessons: 1,
    course_completed: false,
    resume_lesson_id: 'lsn_2',
    ...overrides,
  };
}

export function courseLaunch(overrides: Partial<CourseLaunch> = {}): CourseLaunch {
  return {
    course: { id: 'crs_1', title: 'React Fundamentals', slug: 'react-fundamentals' },
    enrollment: { id: 'enr_1', status: 'active', progress_percentage: 25 },
    progress: { total_lessons: 3, completed_lessons: 1 },
    resume: { lesson_id: 'lsn_2', title: 'Setup' },
    ...overrides,
  };
}

export function lessonContent(overrides: Partial<LessonContent> = {}): LessonContent {
  return {
    id: 'lsn_2',
    title: 'Setup',
    type: 'video',
    blocks: [{ id: 'blk_1', kind: 'text', body: 'Some text' }],
    video: null,
    assessment: null,
    assignment: null,
    ...overrides,
  };
}

export interface FakeQueryResult<T> {
  data: T | undefined;
  isLoading: boolean;
  isError: boolean;
  isFetching: boolean;
  error: unknown;
  refetch: ReturnType<typeof vi.fn>;
}

/** A React-Query-ish query result. Loosely typed so specs can pass it to mocked hooks. */
export function queryResult<T = unknown>(
  over: Partial<FakeQueryResult<T>> = {},
): FakeQueryResult<T> {
  return {
    data: undefined,
    isLoading: false,
    isError: false,
    isFetching: false,
    error: null,
    refetch: vi.fn(),
    ...over,
  };
}

/** A React-Query-ish mutation result. */
export function mutationResult(over: Record<string, unknown> = {}) {
  return {
    mutate: vi.fn(),
    mutateAsync: vi.fn().mockResolvedValue(undefined),
    isPending: false,
    isError: false,
    error: null,
    reset: vi.fn(),
    ...over,
  };
}
