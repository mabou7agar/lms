'use client';

import { useAssignmentsI18n } from '@/lib/assignments/assignments-i18n';
import type { LatePolicy } from './types';
import { isPastDue } from './utils';

interface LateWarningProps {
  dueAt: string | null;
  latePolicy: LatePolicy;
  latePenaltyPercent?: number | null;
  now?: number;
  className?: string;
}

/**
 * Shown when the assignment is past due. Message depends on the late policy — a `reject` policy
 * blocks submission entirely (the parent disables Submit); `penalize`/`accept` allow a late submit
 * with a warning.
 */
export function LateWarning({
  dueAt,
  latePolicy,
  latePenaltyPercent,
  now,
  className,
}: LateWarningProps) {
  const { t } = useAssignmentsI18n();
  if (!isPastDue(dueAt, now)) return null;

  const rejected = latePolicy === 'reject';
  const message = rejected
    ? t('assignments.submission.late.rejected', 'This assignment is past due and no longer accepts submissions.')
    : latePolicy === 'penalize' && latePenaltyPercent != null
      ? t(
          'assignments.submission.late.penalized',
          `This assignment is past due. Submitting now applies a ${latePenaltyPercent}% late penalty.`,
        )
      : t('assignments.submission.late.accepted', 'This assignment is past due. Your submission will be marked late.');

  return (
    <div
      role="alert"
      data-testid="late-warning"
      data-late-policy={latePolicy}
      className={`flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 ${className ?? ''}`}
    >
      <span aria-hidden className="mt-0.5">
        ⚠️
      </span>
      <span>{message}</span>
    </div>
  );
}
