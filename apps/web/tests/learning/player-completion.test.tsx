import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';

import { renderWithI18n } from '../render';
import { CompletionControls } from '@/components/learning/player/CompletionControls';

vi.mock('@/components/ui', () => ({
  Button: ({ children, as: As = 'button', ...rest }: any) => <As {...rest}>{children}</As>,
  Badge: ({ children, ...rest }: any) => <span {...rest}>{children}</span>,
}));

vi.mock('@/lib/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), del: vi.fn(), data: (x: any) => x },
  ApiRequestError: class ApiRequestError extends Error {},
}));

const mutate = vi.fn();
vi.mock('@/lib/learning/player-hooks', () => ({
  learningPlayerKeys: {},
  useCompleteLesson: () => ({ mutate, isPending: false }),
}));

beforeEach(() => mutate.mockReset());

describe('CompletionControls (server-authoritative)', () => {
  it('reflects a completed lesson without any client-side complete action', () => {
    renderWithI18n(<CompletionControls courseId="crs_1" lessonId="lsn_2" completed />);
    expect(screen.getByTestId('lesson-completed')).toBeInTheDocument();
    expect(screen.queryByTestId('completion-controls')).not.toBeInTheDocument();
  });

  it('calls the backend and reports the server progress on success', () => {
    mutate.mockImplementation((_id: string, opts: any) =>
      opts?.onSuccess?.({ status: 'completed', course_progress_percentage: 66 }),
    );
    const onCompleted = vi.fn();
    renderWithI18n(
      <CompletionControls courseId="crs_1" lessonId="lsn_2" completed={false} onCompleted={onCompleted} />,
    );
    fireEvent.click(screen.getByText(/mark as complete/i));
    expect(mutate).toHaveBeenCalledWith('lsn_2', expect.any(Object));
    expect(onCompleted).toHaveBeenCalledWith(66);
  });

  it('surfaces LEARNING_COMPLETION_BLOCKED instead of marking complete', () => {
    mutate.mockImplementation((_id: string, opts: any) =>
      opts?.onError?.({ code: 'LEARNING_COMPLETION_BLOCKED' }),
    );
    renderWithI18n(<CompletionControls courseId="crs_1" lessonId="lsn_2" completed={false} />);
    fireEvent.click(screen.getByText(/mark as complete/i));
    expect(screen.getByTestId('completion-blocked')).toBeInTheDocument();
  });
});
