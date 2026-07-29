import { describe, expect, it, vi } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';
import { makeLearnerSubmission, renderWithI18n, rubricFixture } from './_helpers';

vi.mock('@/lib/assignments/assignments-i18n', () => ({
  useAssignmentsI18n: () => ({ t: (_k: string, f?: string) => f ?? _k }),
}));

import { SubmissionHistory } from '@/components/assignments/submission/SubmissionHistory';
import { AttemptCounter } from '@/components/assignments/submission/AttemptCounter';
import { ChangesRequestedBanner } from '@/components/assignments/submission/ChangesRequestedBanner';

describe('SubmissionHistory', () => {
  it('lists attempts newest-first and shows released scores only', () => {
    const subs = [
      makeLearnerSubmission({ id: 's1', attempt_no: 1, status: 'released', submitted_at: '2026-07-01T00:00:00Z', grade: { score: 80, passed: true, feedback: 'ok', rubric_result: null, released_at: '2026-07-02T00:00:00Z' } }),
      makeLearnerSubmission({ id: 's2', attempt_no: 2, status: 'submitted', submitted_at: '2026-07-10T00:00:00Z', is_late: true, grade: null }),
    ];
    renderWithI18n(<SubmissionHistory submissions={subs} maxGrade={100} />);
    const rows = screen.getAllByTestId(/history-row-/);
    // newest attempt (2) first
    expect(rows[0]).toHaveAttribute('data-testid', 'history-row-2');
    expect(screen.getByTestId('history-score')).toHaveTextContent('80 / 100');
    expect(screen.getByTestId('history-late')).toBeInTheDocument();
  });

  it('calls onSelect with the chosen attempt', () => {
    const onSelect = vi.fn();
    renderWithI18n(
      <SubmissionHistory submissions={[makeLearnerSubmission({ attempt_no: 1 })]} onSelect={onSelect} />,
    );
    fireEvent.click(screen.getByTestId('history-row-1'));
    expect(onSelect).toHaveBeenCalledTimes(1);
  });

  it('renders an empty state', () => {
    renderWithI18n(<SubmissionHistory submissions={[]} />);
    expect(screen.getByTestId('history-empty')).toBeInTheDocument();
  });
});

describe('AttemptCounter', () => {
  it('shows attempt N of limit', () => {
    renderWithI18n(<AttemptCounter attemptLimit={3} attemptsUsed={1} />);
    const el = screen.getByTestId('attempt-counter');
    expect(el).toHaveAttribute('data-attempts-remaining', '2');
    expect(el).toHaveTextContent('Attempt 2 of 3');
  });

  it('shows unlimited when no limit', () => {
    renderWithI18n(<AttemptCounter attemptLimit={null} attemptsUsed={4} />);
    expect(screen.getByTestId('attempt-counter')).toHaveAttribute(
      'data-attempts-remaining',
      'unlimited',
    );
  });
});

describe('ChangesRequestedBanner', () => {
  it('renders the instructor revision note', () => {
    renderWithI18n(<ChangesRequestedBanner note="Please add citations." />);
    expect(screen.getByTestId('changes-requested-note')).toHaveTextContent('Please add citations.');
  });
});

// sanity: rubric fixture points sum used elsewhere
it('rubric fixture totals are consistent', () => {
  const sum = rubricFixture.criteria.reduce((a, c) => a + c.max_points, 0);
  expect(sum).toBe(20);
});
