import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { makeLearnerSubmission, renderWithI18n } from './_helpers';

vi.mock('@/lib/assignments/assignments-i18n', () => ({
  useAssignmentsI18n: () => ({ t: (_k: string, f?: string) => f ?? _k }),
}));

import { ReleasedGradeView } from '@/components/assignments/submission/ReleasedGradeView';

describe('ReleasedGradeView', () => {
  it('shows score, rubric result and feedback for a released grade', () => {
    const submission = makeLearnerSubmission({
      status: 'released',
      grade: {
        score: 16,
        passed: true,
        feedback: 'Strong evidence, tighten the intro.',
        rubric_result: [
          { criterion_public_id: 'crit-1', level_public_id: 'lvl-1b' },
          { criterion_public_id: 'crit-2', level_public_id: 'lvl-2b' },
        ],
        released_at: '2026-07-20T00:00:00Z',
      },
    });

    renderWithI18n(<ReleasedGradeView submission={submission} maxGrade={100} passingGrade={50} />);

    expect(screen.getByTestId('grade-score')).toHaveTextContent('16');
    expect(screen.getByTestId('grade-passed')).toHaveAttribute('data-passed', 'true');
    // rubric result maps ids to human titles from the snapshot
    const rubric = screen.getByTestId('grade-rubric');
    expect(rubric).toHaveTextContent('Clarity');
    expect(rubric).toHaveTextContent('Good');
    expect(rubric).toHaveTextContent('Evidence');
    expect(rubric).toHaveTextContent('Strong');
    expect(screen.getByTestId('grade-feedback')).toHaveTextContent('tighten the intro');
  });

  it('NEVER renders any private notes text (learner shape carries none)', () => {
    const submission = makeLearnerSubmission({
      status: 'released',
      grade: {
        score: 10,
        passed: false,
        feedback: 'See notes.',
        rubric_result: null,
        released_at: '2026-07-20T00:00:00Z',
      },
    });
    // Cast to any to simulate a leaked field; the component must not read/render it.
    (submission.grade as any).private_notes = 'INTERNAL: suspected plagiarism';

    renderWithI18n(<ReleasedGradeView submission={submission} maxGrade={100} />);
    expect(screen.queryByText(/INTERNAL/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/suspected plagiarism/i)).not.toBeInTheDocument();
  });

  it('shows a pending state when no grade is released', () => {
    renderWithI18n(<ReleasedGradeView submission={makeLearnerSubmission({ grade: null })} />);
    expect(screen.getByTestId('grade-pending')).toBeInTheDocument();
  });
});
