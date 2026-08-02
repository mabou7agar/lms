'use client';

import { useAssignmentsI18n } from '@/lib/assignments/assignments-i18n';

interface ChangesRequestedBannerProps {
  /** Optional instructor note explaining what to revise. */
  note?: string | null;
  className?: string;
}

/**
 * Banner shown when a submission is in `changes_requested`. Surfaces the instructor's revision note
 * (which lives on the submission's feedback/note channel, NOT private_notes) and cues the learner
 * to edit their draft and resubmit.
 */
export function ChangesRequestedBanner({ note, className }: ChangesRequestedBannerProps) {
  const { t } = useAssignmentsI18n();
  return (
    <div
      role="alert"
      data-testid="changes-requested"
      className={`space-y-1 rounded-xl border border-gold/30 bg-gold/[0.08] p-4 text-sm text-foreground ${className ?? ''}`}
    >
      <p className="font-serif text-base font-semibold">
        {t('assignments.submission.changesRequested.title', 'Changes requested')}
      </p>
      <p className="text-muted-foreground">
        {t(
          'assignments.submission.changesRequested.body',
          'Your instructor asked for revisions. Update your work and resubmit.',
        )}
      </p>
      {note && (
        <blockquote
          data-testid="changes-requested-note"
          className="mt-2 border-s-2 border-gold ps-3 text-foreground"
        >
          {note}
        </blockquote>
      )}
    </div>
  );
}
