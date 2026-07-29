import { render, type RenderOptions } from '@testing-library/react';
import type { ReactElement } from 'react';
import type {
  LearnerAssignment,
  LearnerSubmission,
  Rubric,
} from '@/components/assignments/submission/types';
import type {
  InstructorSubmission,
  QueueRow,
} from '@/components/assignments/grading/types';

/**
 * Local render helper for the D4 suites. i18n is mocked to an identity `t(key, fallback)` in each
 * test file, so a plain render is sufficient — no real provider tree required (all data hooks are
 * mocked). Named `renderWithI18n` to match the wave convention.
 */
export function renderWithI18n(ui: ReactElement, options?: RenderOptions) {
  return render(ui, options);
}

export const rubricFixture: Rubric = {
  id: 'rub-1',
  title: 'Essay rubric',
  total_points: 20,
  criteria: [
    {
      id: 'crit-1',
      title: 'Clarity',
      description: 'How clear is the argument',
      position: 0,
      max_points: 10,
      levels: [
        { id: 'lvl-1a', title: 'Poor', description: null, points: 0, position: 0 },
        { id: 'lvl-1b', title: 'Good', description: null, points: 6, position: 1 },
        { id: 'lvl-1c', title: 'Excellent', description: null, points: 10, position: 2 },
      ],
    },
    {
      id: 'crit-2',
      title: 'Evidence',
      description: null,
      position: 1,
      max_points: 10,
      levels: [
        { id: 'lvl-2a', title: 'Weak', description: null, points: 2, position: 0 },
        { id: 'lvl-2b', title: 'Strong', description: null, points: 10, position: 1 },
      ],
    },
  ],
};

export function makeAssignment(overrides: Partial<LearnerAssignment> = {}): LearnerAssignment {
  return {
    id: 'asg-1',
    title: 'Photosynthesis essay',
    instructions: 'Write 500 words.',
    submission_type: 'text_file',
    allowed_file_types: ['pdf', 'docx'],
    max_file_size: 5 * 1024 * 1024,
    max_files: 2,
    attempt_limit: 3,
    due_at: '2026-08-01T00:00:00+00:00',
    late_policy: 'penalize',
    max_grade: 100,
    passing_grade: 50,
    rubric: rubricFixture,
    ...overrides,
  };
}

export function makeLearnerSubmission(
  overrides: Partial<LearnerSubmission> = {},
): LearnerSubmission {
  return {
    id: 'sub-1',
    attempt_no: 1,
    status: 'draft',
    submitted_at: null,
    is_late: false,
    text_response: '',
    external_url: null,
    files: [],
    rubric_snapshot: rubricFixture,
    grade: null,
    ...overrides,
  };
}

export function makeInstructorSubmission(
  overrides: Partial<InstructorSubmission> = {},
): InstructorSubmission {
  return {
    id: 'sub-1',
    assignment_id: 'asg-1',
    learner_id: 42,
    attempt_no: 1,
    status: 'submitted',
    submitted_at: '2026-07-25T10:00:00+00:00',
    is_late: false,
    text_response: 'My essay body.',
    external_url: null,
    files: [{ id: 'f-1', media_id: 'media-1', filename: 'essay.pdf' }],
    rubric_snapshot: rubricFixture,
    grade: null,
    ...overrides,
  };
}

export function makeQueueRow(overrides: Partial<QueueRow> = {}): QueueRow {
  return {
    id: 'sub-1',
    learner_id: 42,
    attempt_no: 1,
    status: 'submitted',
    submitted_at: '2026-07-25T10:00:00+00:00',
    is_late: false,
    has_grade: false,
    released: false,
    score: null,
    ...overrides,
  };
}

/** A minimal React Query-shaped mutation stub. */
export function mockMutation(impl?: (vars: unknown) => Promise<unknown>) {
  const mutateAsync = impl
    ? // eslint-disable-next-line @typescript-eslint/no-explicit-any
      ((vars: unknown) => impl(vars)) as any
    : // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (async () => undefined) as any;
  return { mutateAsync, mutate: mutateAsync, isPending: false, isError: false, error: null, reset: () => {} };
}

/** A minimal React Query-shaped query stub. */
export function mockQuery<T>(data: T, extra: Record<string, unknown> = {}) {
  return {
    data,
    isLoading: false,
    isError: false,
    isFetching: false,
    error: null,
    refetch: async () => ({ data }),
    ...extra,
  };
}
