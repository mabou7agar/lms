import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';

import { renderWithI18n } from '../render';
import { CoursePlayerShell } from '@/components/learning/player/CoursePlayerShell';
import { curriculum, mutationResult, progressSummary, queryResult } from './player-test-helpers';

vi.mock('@/components/ui', () => ({
  Button: ({ children, as: As = 'button', ...rest }: any) => <As {...rest}>{children}</As>,
  Badge: ({ children, ...rest }: any) => <span {...rest}>{children}</span>,
  Spinner: (rest: any) => <span {...rest} />,
  Skeleton: (rest: any) => <div {...rest} />,
  Drawer: ({ open, children }: any) => (open ? <div>{children}</div> : null),
  toast: { success: vi.fn(), error: vi.fn() },
}));

vi.mock('@/lib/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), del: vi.fn(), data: (x: any) => x },
  ApiRequestError: class ApiRequestError extends Error {},
}));

vi.mock('@/lib/learning/player-hooks', () => ({
  learningPlayerKeys: {},
  useCurriculum: () => queryResult({ data: curriculum() }),
  useProgressSummary: () => queryResult({ data: progressSummary() }),
  useResumePointer: () => queryResult({}),
  useLessonContent: () => queryResult({ data: { id: 'lsn_2', title: 'إعداد', type: 'text', blocks: [] } }),
  useLessonPlayback: () => queryResult({}),
  useLaunchCourse: () => mutationResult(),
  useMarkLessonViewed: () => mutationResult(),
  useCompleteLesson: () => mutationResult(),
  useCompleteBlock: () => mutationResult(),
  useRecordVideoProgress: () => mutationResult(),
}));

describe('CoursePlayerShell RTL', () => {
  it('renders right-to-left with Arabic strings when locale=ar', () => {
    renderWithI18n(<CoursePlayerShell courseId="crs_1" locale="ar" />);

    const root = screen.getByTestId('course-player');
    expect(root).toHaveAttribute('dir', 'rtl');
    // Arabic progress copy is rendered (module-local i18n switched to ar).
    expect(screen.getByText(/اكتمل 25%/)).toBeInTheDocument();
    // Sidebar nav carries the Arabic accessible label.
    expect(screen.getAllByLabelText('محتوى الدورة').length).toBeGreaterThan(0);
  });
});
