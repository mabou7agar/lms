import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';

import { renderWithI18n } from '../render';
import { LessonView } from '@/components/learning/player/LessonView';
import { curriculum, lessonContent, mutationResult, queryResult } from './player-test-helpers';

vi.mock('@/components/ui', () => ({
  Button: ({ children, as: As = 'button', ...rest }: any) => <As {...rest}>{children}</As>,
  Badge: ({ children, ...rest }: any) => <span {...rest}>{children}</span>,
  Skeleton: (rest: any) => <div {...rest} />,
  Spinner: (rest: any) => <span {...rest} />,
}));

vi.mock('@/lib/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), del: vi.fn(), data: (x: any) => x },
  ApiRequestError: class ApiRequestError extends Error {},
}));

const hooks = vi.hoisted(() => ({
  lessonContent: {} as any,
  markViewed: {} as any,
  completeLesson: {} as any,
  completeBlock: {} as any,
  playback: {} as any,
  recordVideo: {} as any,
}));

vi.mock('@/lib/learning/player-hooks', () => ({
  learningPlayerKeys: {},
  useLessonContent: () => hooks.lessonContent,
  useMarkLessonViewed: () => hooks.markViewed,
  useCompleteLesson: () => hooks.completeLesson,
  useCompleteBlock: () => hooks.completeBlock,
  useLessonPlayback: () => hooks.playback,
  useRecordVideoProgress: () => hooks.recordVideo,
  useCurriculum: () => queryResult({ data: curriculum() }),
  useProgressSummary: () => queryResult({}),
  useResumePointer: () => queryResult({}),
  useLaunchCourse: () => mutationResult(),
}));

beforeEach(() => {
  hooks.lessonContent = queryResult({
    data: lessonContent({
      blocks: [],
      assessment: { id: 'asm_1', title: 'Module quiz', status: 'not_started' },
      assignment: { id: 'asg_1', title: 'Build a component' },
    }),
  });
  hooks.markViewed = mutationResult();
  hooks.completeLesson = mutationResult();
  hooks.completeBlock = mutationResult();
  hooks.playback = queryResult({});
  hooks.recordVideo = mutationResult();
});

describe('LessonView launch entry points', () => {
  it('renders assessment + assignment launchers and hands off their ids', () => {
    const onLaunchAssessment = vi.fn();
    const onLaunchAssignment = vi.fn();

    renderWithI18n(
      <LessonView
        courseId="crs_1"
        curriculum={curriculum()}
        lessonId="lsn_2"
        onNavigate={vi.fn()}
        onLaunchAssessment={onLaunchAssessment}
        onLaunchAssignment={onLaunchAssignment}
      />,
    );

    expect(screen.getByTestId('assessment-launch')).toHaveTextContent('Module quiz');
    expect(screen.getByTestId('assignment-launch')).toHaveTextContent('Build a component');

    fireEvent.click(screen.getByText(/start assessment/i));
    expect(onLaunchAssessment).toHaveBeenCalledWith('asm_1');

    fireEvent.click(screen.getByText(/open assignment/i));
    expect(onLaunchAssignment).toHaveBeenCalledWith('asg_1');
  });

  it('marks the lesson viewed on entry (server-authoritative progress signal)', () => {
    renderWithI18n(
      <LessonView courseId="crs_1" curriculum={curriculum()} lessonId="lsn_2" onNavigate={vi.fn()} />,
    );
    expect(hooks.markViewed.mutate).toHaveBeenCalledWith('lsn_2');
  });
});
