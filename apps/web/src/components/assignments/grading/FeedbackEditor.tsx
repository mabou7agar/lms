'use client';

import { useAssignmentsI18n } from '@/lib/assignments/assignments-i18n';

interface FeedbackEditorProps {
  feedback: string;
  privateNotes: string;
  onFeedbackChange: (value: string) => void;
  onPrivateNotesChange: (value: string) => void;
  disabled?: boolean;
}

/**
 * Written feedback (released to the learner) + private instructor notes (grader-only, NEVER released
 * — the learner resource strips them regardless of wiring). The private field is clearly labelled so
 * a grader is never confused about what the learner will see.
 */
export function FeedbackEditor({
  feedback,
  privateNotes,
  onFeedbackChange,
  onPrivateNotesChange,
  disabled,
}: FeedbackEditorProps) {
  const { t } = useAssignmentsI18n();
  return (
    <section data-testid="feedback-editor" className="space-y-4">
      <label className="block">
        <span className="mb-1 block text-sm font-semibold text-slate-800">
          {t('assignments.grading.feedback.label', 'Feedback for the learner')}
        </span>
        <span className="mb-1 block text-xs text-slate-500">
          {t('assignments.grading.feedback.hint', 'Shared with the learner when the grade is released.')}
        </span>
        <textarea
          data-testid="feedback-input"
          value={feedback}
          disabled={disabled}
          rows={5}
          maxLength={20000}
          onChange={(e) => onFeedbackChange(e.target.value)}
          className="w-full rounded-md border border-slate-300 p-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-slate-50"
        />
      </label>

      <label className="block">
        <span className="mb-1 flex items-center gap-2 text-sm font-semibold text-slate-800">
          {t('assignments.grading.privateNotes.label', 'Private notes')}
          <span
            data-testid="private-notes-badge"
            className="rounded-full bg-slate-800 px-2 py-0.5 text-[10px] font-semibold uppercase text-white"
          >
            {t('assignments.grading.privateNotes.badge', 'Instructor only')}
          </span>
        </span>
        <span className="mb-1 block text-xs text-slate-500">
          {t('assignments.grading.privateNotes.hint', 'Never shown to the learner.')}
        </span>
        <textarea
          data-testid="private-notes-input"
          value={privateNotes}
          disabled={disabled}
          rows={4}
          maxLength={20000}
          onChange={(e) => onPrivateNotesChange(e.target.value)}
          className="w-full rounded-md border border-amber-300 bg-amber-50/40 p-2 text-sm focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 disabled:bg-slate-50"
        />
      </label>
    </section>
  );
}
