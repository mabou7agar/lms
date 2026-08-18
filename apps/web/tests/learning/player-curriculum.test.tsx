import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';

import { renderWithI18n } from '../render';
import { CoursePlayerShell } from '@/components/learning/player/CoursePlayerShell';
import { curriculum, mutationResult, progressSummary, queryResult } from './player-test-helpers';

// --- ui-kit stubs (deterministic, no portals) ---------------------------------
vi.mock('@/components/ui', () => ({
  Button: ({ children, as: As = 'button', ...rest }: any) => <As {...rest}>{children}</As>,
  Badge: ({ children, ...rest }: any) => <span {...rest}>{children}</span>,
  Spinner: (rest: any) => <span data-testid="spinner" {...rest} />,
  Skeleton: (rest: any) => <div data-testid="skeleton" {...rest} />,
  Drawer: ({ open, children }: any) => (open ? <div data-testid="drawer">{children}</div> : null),
  DrawerContent: ({ children, ...rest }: any) => <div {...rest}>{children}</div>,
  DrawerTitle: ({ children, ...rest }: any) => <h2 {...rest}>{children}</h2>,
  toast: { success: vi.fn(), error: vi.fn() },
}));

// The player now surfaces lesson/course files. These tests render without a QueryClient, so stub the
// resource hooks rather than pulling React Query into every player test.
vi.mock('@/lib/courseware/hooks', () => ({
  useCourseResources: () => ({ isPending: false, isError: false, refetch: vi.fn(), data: { items: [], entitled: true } }),
  useLessonResources: () => ({ isPending: false, isError: false, refetch: vi.fn(), data: { items: [], entitled: true } }),
  useDownloadResource: () => ({ mutate: vi.fn(), isPending: false, variables: undefined }),
}));

vi.mock('@/lib/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), del: vi.fn(), data: (x: any) => x },
  ApiRequestError: class ApiRequestError extends Error {},
}));

const hooks = vi.hoisted(() => ({
  curriculum: {} as any,
  summary: {} as any,
  lessonContent: {} as any,
  playback: {} as any,
  markViewed: {} as any,
  completeLesson: {} as any,
  completeBlock: {} as any,
  recordVideo: {} as any,
  launch: {} as any,
  resume: {} as any,
}));

vi.mock('@/lib/learning/player-hooks', () => ({
  learningPlayerKeys: {},
  useCurriculum: () => hooks.curriculum,
  useProgressSummary: () => hooks.summary,
  useResumePointer: () => hooks.resume,
  useLessonContent: () => hooks.lessonContent,
  useLessonPlayback: () => hooks.playback,
  useLaunchCourse: () => hooks.launch,
  useMarkLessonViewed: () => hooks.markViewed,
  useCompleteLesson: () => hooks.completeLesson,
  useCompleteBlock: () => hooks.completeBlock,
  useRecordVideoProgress: () => hooks.recordVideo,
}));

beforeEach(() => {
  hooks.curriculum = queryResult({ isLoading: true });
  hooks.summary = queryResult({ data: progressSummary() });
  hooks.resume = queryResult({ data: { resume_lesson_id: 'lsn_2', title: 'Setup' } });
  hooks.lessonContent = queryResult({ data: { id: 'lsn_2', title: 'Setup', type: 'video', blocks: [] } });
  hooks.playback = queryResult({ data: undefined });
  hooks.markViewed = mutationResult();
  hooks.completeLesson = mutationResult();
  hooks.completeBlock = mutationResult();
  hooks.recordVideo = mutationResult();
  hooks.launch = mutationResult();
});

describe('CoursePlayerShell curriculum', () => {
  it('shows a loading state then the ready curriculum', () => {
    const { rerender } = renderWithI18n(<CoursePlayerShell courseId="crs_1" />);
    expect(screen.getByTestId('player-loading')).toBeInTheDocument();

    hooks.curriculum = queryResult({ data: curriculum(), isLoading: false });
    rerender(<CoursePlayerShell courseId="crs_1" />);

    expect(screen.getByTestId('course-player')).toBeInTheDocument();
    expect(screen.getByText('React Fundamentals')).toBeInTheDocument();
    // Sections + navigable lessons render.
    expect(screen.getByTestId('lesson-link-lsn_1')).toBeInTheDocument();
    expect(screen.getByTestId('lesson-link-lsn_2')).toBeInTheDocument();
  });

  it('renders a locked lesson as non-navigable with its reason', () => {
    hooks.curriculum = queryResult({ data: curriculum(), isLoading: false });
    renderWithI18n(<CoursePlayerShell courseId="crs_1" />);

    // Locked lesson is not a button and carries a reason.
    expect(screen.queryByTestId('lesson-link-lsn_3')).not.toBeInTheDocument();
    const locked = screen.getByTestId('lesson-locked-lsn_3');
    expect(locked).toHaveAttribute('aria-disabled', 'true');
    expect(locked).toHaveTextContent(/Complete the previous lessons/i);
  });
});
