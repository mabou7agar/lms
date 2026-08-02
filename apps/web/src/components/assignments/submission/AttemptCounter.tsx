'use client';

import { useAssignmentsI18n } from '@/lib/assignments/assignments-i18n';
import { attemptsRemaining } from './utils';

interface AttemptCounterProps {
  attemptLimit: number | null;
  attemptsUsed: number;
  className?: string;
}

/** Compact "Attempt X of Y" / "unlimited attempts" indicator. */
export function AttemptCounter({ attemptLimit, attemptsUsed, className }: AttemptCounterProps) {
  const { t } = useAssignmentsI18n();
  const remaining = attemptsRemaining(attemptLimit, attemptsUsed);

  const label =
    attemptLimit == null
      ? t('assignments.submission.attempts.unlimited', `${attemptsUsed} attempt(s) used · unlimited`)
      : t(
          'assignments.submission.attempts.counted',
          `Attempt ${Math.min(attemptsUsed + 1, attemptLimit)} of ${attemptLimit}`,
        );

  return (
    <span
      data-testid="attempt-counter"
      data-attempts-used={attemptsUsed}
      data-attempt-limit={attemptLimit ?? 'unlimited'}
      data-attempts-remaining={remaining ?? 'unlimited'}
      className={`inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-foreground ${className ?? ''}`}
    >
      {label}
    </span>
  );
}
