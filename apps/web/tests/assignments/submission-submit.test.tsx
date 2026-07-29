import { describe, expect, it, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { renderWithI18n } from './_helpers';

const h = vi.hoisted(() => ({
  submit: vi.fn(async () => undefined),
  resubmit: vi.fn(async () => undefined),
}));

vi.mock('@/lib/assignments/assignments-i18n', () => ({
  useAssignmentsI18n: () => ({ t: (_k: string, f?: string) => f ?? _k }),
}));
vi.mock('@/components/ui', () => ({
  Button: ({ children, ...props }: any) => <button {...props}>{children}</button>,
}));
vi.mock('@/lib/assignments/assignments-hooks', () => ({
  useSubmitAssignment: () => ({ mutateAsync: h.submit, isPending: false, isError: false }),
  useResubmitAssignment: () => ({ mutateAsync: h.resubmit, isPending: false, isError: false }),
}));

import { SubmitConfirmation } from '@/components/assignments/submission/SubmitConfirmation';

const PAST = '2020-01-01T00:00:00+00:00';
const FUTURE = '2999-01-01T00:00:00+00:00';

describe('SubmitConfirmation', () => {
  beforeEach(() => {
    h.submit.mockClear();
    h.resubmit.mockClear();
  });

  it('confirms and submits via the confirmation dialog', async () => {
    renderWithI18n(
      <SubmitConfirmation
        assignmentId="asg-1"
        mode="submit"
        dueAt={FUTURE}
        latePolicy="penalize"
        attemptLimit={3}
        attemptsUsed={0}
      />,
    );
    fireEvent.click(screen.getByTestId('submit-open'));
    expect(screen.getByTestId('submit-dialog')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('submit-confirm'));
    await waitFor(() => expect(h.submit).toHaveBeenCalledTimes(1));
  });

  it('shows a late warning inside the dialog when past due', () => {
    renderWithI18n(
      <SubmitConfirmation
        assignmentId="asg-1"
        mode="submit"
        dueAt={PAST}
        latePolicy="penalize"
        latePenaltyPercent={10}
        attemptLimit={3}
        attemptsUsed={0}
      />,
    );
    fireEvent.click(screen.getByTestId('submit-open'));
    expect(screen.getByTestId('late-warning')).toBeInTheDocument();
    expect(screen.getByTestId('late-warning')).toHaveTextContent(/10% late penalty/i);
  });

  it('blocks submission when the late policy rejects late work', () => {
    renderWithI18n(
      <SubmitConfirmation
        assignmentId="asg-1"
        mode="submit"
        dueAt={PAST}
        latePolicy="reject"
        attemptLimit={3}
        attemptsUsed={0}
      />,
    );
    expect(screen.getByTestId('submit-open')).toBeDisabled();
  });

  it('blocks submission when no attempts remain', () => {
    renderWithI18n(
      <SubmitConfirmation
        assignmentId="asg-1"
        mode="submit"
        dueAt={FUTURE}
        latePolicy="accept"
        attemptLimit={2}
        attemptsUsed={2}
      />,
    );
    expect(screen.getByTestId('submit-open')).toBeDisabled();
    expect(screen.getByTestId('no-attempts')).toBeInTheDocument();
  });

  it('uses the resubmit mutation in resubmit mode', async () => {
    renderWithI18n(
      <SubmitConfirmation
        assignmentId="asg-1"
        mode="resubmit"
        dueAt={FUTURE}
        latePolicy="accept"
        attemptLimit={3}
        attemptsUsed={1}
      />,
    );
    fireEvent.click(screen.getByTestId('submit-open'));
    fireEvent.click(screen.getByTestId('submit-confirm'));
    await waitFor(() => expect(h.resubmit).toHaveBeenCalledTimes(1));
    expect(h.submit).not.toHaveBeenCalled();
  });
});
