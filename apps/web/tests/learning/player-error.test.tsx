import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';

import { renderWithI18n } from '../render';
import { CoursePlayerShell } from '@/components/learning/player/CoursePlayerShell';
import { curriculum, mutationResult, progressSummary, queryResult } from './player-test-helpers';

vi.mock('@/components/ui', () => ({
  Button: ({ children, as: As = 'button', ...rest }: any) => <As {...rest}>{children}</As>,
  Badge: ({ children, ...rest }: any) => <span {...rest}>{children}</span>,
  Spinner: (rest: any) => <span data-testid="spinner" {...rest} />,
  Skeleton: (rest: any) => <div {...rest} />,
  Drawer: ({ open, children }: any) => (open ? <div>{children}</div> : null),
  DrawerContent: ({ children, ...rest }: any) => <div {...rest}>{children}</div>,
  DrawerTitle: ({ children, ...rest }: any) => <h2 {...rest}>{children}</h2>,
  toast: { success: vi.fn(), error: vi.fn() },
}));

vi.mock('@/lib/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), del: vi.fn(), data: (x: any) => x },
  ApiRequestError: class ApiRequestError extends Error {},
}));

const refetch = vi.fn();
const hooks = vi.hoisted(() => ({ curriculum: {} as any, summary: {} as any }));

vi.mock('@/lib/learning/player-hooks', () => ({
  learningPlayerKeys: {},
  useCurriculum: () => hooks.curriculum,
  useProgressSummary: () => hooks.summary,
  useResumePointer: () => queryResult({}),
  useLessonContent: () => queryResult({}),
  useLessonPlayback: () => queryResult({}),
  useLaunchCourse: () => mutationResult(),
  useMarkLessonViewed: () => mutationResult(),
  useCompleteLesson: () => mutationResult(),
  useCompleteBlock: () => mutationResult(),
  useRecordVideoProgress: () => mutationResult(),
}));

beforeEach(() => {
  refetch.mockClear();
  hooks.summary = queryResult({ data: progressSummary() });
  hooks.curriculum = queryResult({ isError: true, refetch });
});

describe('CoursePlayerShell error + recovery', () => {
  it('shows an error panel and recovers by refetching', () => {
    const { rerender } = renderWithI18n(<CoursePlayerShell courseId="crs_1" />);

    expect(screen.getByTestId('player-error')).toBeInTheDocument();

    fireEvent.click(screen.getByText(/try again/i));
    expect(refetch).toHaveBeenCalledTimes(1);

    // Recovery: refetch succeeds, curriculum resolves.
    hooks.curriculum = queryResult({ data: curriculum(), isError: false });
    rerender(<CoursePlayerShell courseId="crs_1" />);

    expect(screen.queryByTestId('player-error')).not.toBeInTheDocument();
    expect(screen.getByTestId('course-player')).toBeInTheDocument();
  });
});
