import { describe, expect, it, vi } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';

import { renderWithI18n } from '../render';
import { ProgressDisplay } from '@/components/learning/player/ProgressDisplay';

vi.mock('@/components/ui', () => ({
  Button: ({ children, as: As = 'button', ...rest }: any) => <As {...rest}>{children}</As>,
}));

describe('ProgressDisplay resume', () => {
  it('renders server-authoritative progress and resumes to the last lesson', () => {
    const onResume = vi.fn();
    renderWithI18n(
      <ProgressDisplay
        progressPercentage={42}
        completedLessons={3}
        totalLessons={7}
        resumeLessonId="lsn_5"
        resumeTitle="Hooks in depth"
        onResume={onResume}
      />,
    );

    const bar = screen.getByRole('progressbar');
    expect(bar).toHaveAttribute('aria-valuenow', '42');
    expect(screen.getByText(/3 of 7 lessons complete/i)).toBeInTheDocument();

    fireEvent.click(screen.getByTestId('resume-button'));
    expect(onResume).toHaveBeenCalledWith('lsn_5');
  });

  it('shows course-complete state and no resume when there is nothing to resume', () => {
    renderWithI18n(
      <ProgressDisplay progressPercentage={100} courseCompleted resumeLessonId={null} />,
    );
    expect(screen.getByText(/course complete/i)).toBeInTheDocument();
    expect(screen.queryByTestId('resume-button')).not.toBeInTheDocument();
  });
});
