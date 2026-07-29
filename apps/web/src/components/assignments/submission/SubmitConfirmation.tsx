'use client';

import { useState } from 'react';
import { Button } from '@/components/ui';
import {
  useResubmitAssignment,
  useSubmitAssignment,
} from '@/lib/assignments/assignments-hooks';
import { useAssignmentsI18n } from '@/lib/assignments/assignments-i18n';
import type { LatePolicy } from './types';
import { LateWarning } from './LateWarning';
import { canSubmitAgain, isPastDue } from './utils';

interface SubmitConfirmationProps {
  assignmentId: string;
  mode: 'submit' | 'resubmit';
  dueAt: string | null;
  latePolicy: LatePolicy;
  latePenaltyPercent?: number | null;
  attemptLimit: number | null;
  attemptsUsed: number;
  /** Blocks confirm while files are still uploading or nothing has been entered. */
  disabled?: boolean;
  onSubmitted?: () => void;
  now?: number;
}

/**
 * Two-step submit: an explicit confirmation dialog restates the late/attempt consequences before
 * the (final) submit. A `reject` late policy or an exhausted attempt limit blocks confirmation.
 */
export function SubmitConfirmation({
  assignmentId,
  mode,
  dueAt,
  latePolicy,
  latePenaltyPercent,
  attemptLimit,
  attemptsUsed,
  disabled,
  onSubmitted,
  now,
}: SubmitConfirmationProps) {
  const { t } = useAssignmentsI18n();
  const submit = useSubmitAssignment(assignmentId);
  const resubmit = useResubmitAssignment(assignmentId);
  const mutation = mode === 'resubmit' ? resubmit : submit;

  const [open, setOpen] = useState(false);

  const lateRejected = latePolicy === 'reject' && isPastDue(dueAt, now);
  const noAttempts = !canSubmitAgain(attemptLimit, attemptsUsed);
  const blocked = Boolean(disabled) || lateRejected || noAttempts;

  const confirm = async () => {
    await mutation.mutateAsync(undefined);
    setOpen(false);
    onSubmitted?.();
  };

  return (
    <>
      <Button
        type="button"
        variant="primary"
        data-testid="submit-open"
        disabled={blocked || mutation.isPending}
        onClick={() => setOpen(true)}
      >
        {mode === 'resubmit'
          ? t('assignments.submission.resubmit', 'Resubmit')
          : t('assignments.submission.submit', 'Submit')}
      </Button>

      {noAttempts && (
        <p className="mt-1 text-xs text-slate-500" data-testid="no-attempts">
          {t('assignments.submission.noAttempts', 'You have no attempts remaining.')}
        </p>
      )}

      {open && (
        <div
          role="dialog"
          aria-modal="true"
          aria-label={t('assignments.submission.confirm.title', 'Confirm submission')}
          data-testid="submit-dialog"
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        >
          <div className="w-full max-w-md space-y-4 rounded-lg bg-white p-5 shadow-xl">
            <h2 className="text-base font-semibold text-slate-900">
              {t('assignments.submission.confirm.title', 'Confirm submission')}
            </h2>
            <p className="text-sm text-slate-600">
              {t(
                'assignments.submission.confirm.body',
                'Once submitted, your work will be sent for grading. Make sure everything is ready.',
              )}
            </p>

            <LateWarning
              dueAt={dueAt}
              latePolicy={latePolicy}
              latePenaltyPercent={latePenaltyPercent}
              now={now}
            />

            {mutation.isError && (
              <p role="alert" className="text-sm text-red-600" data-testid="submit-error">
                {t('assignments.submission.confirm.error', 'Submission failed. Please try again.')}
              </p>
            )}

            <div className="flex justify-end gap-2">
              <Button
                type="button"
                variant="ghost"
                data-testid="submit-cancel"
                disabled={mutation.isPending}
                onClick={() => setOpen(false)}
              >
                {t('common.cancel', 'Cancel')}
              </Button>
              <Button
                type="button"
                variant="primary"
                data-testid="submit-confirm"
                disabled={mutation.isPending}
                onClick={() => void confirm()}
              >
                {mutation.isPending
                  ? t('assignments.submission.confirm.submitting', 'Submitting…')
                  : t('assignments.submission.confirm.confirm', 'Confirm & submit')}
              </Button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
