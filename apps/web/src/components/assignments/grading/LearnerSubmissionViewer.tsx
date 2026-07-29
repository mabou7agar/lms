'use client';

import { useAssignmentsI18n } from '@/lib/assignments/assignments-i18n';
import { StatusBadge } from '../submission/StatusBadge';
import { SubmissionFileList, type FileUrlResolver } from './SubmissionFileList';
import type { InstructorSubmission } from './types';

interface LearnerSubmissionViewerProps {
  submission: InstructorSubmission;
  resolveFileUrl?: FileUrlResolver;
}

function formatDate(iso: string | null): string {
  if (!iso) return '—';
  const ms = Date.parse(iso);
  return Number.isFinite(ms) ? new Date(ms).toLocaleString() : '—';
}

/**
 * Read-only rendering of what the learner handed in — text, external URL and files (behind secure
 * on-demand access). This is the left pane of the grading view.
 */
export function LearnerSubmissionViewer({ submission, resolveFileUrl }: LearnerSubmissionViewerProps) {
  const { t } = useAssignmentsI18n();
  return (
    <section data-testid="submission-viewer" className="space-y-4">
      <header className="flex flex-wrap items-center gap-2">
        <h3 className="text-sm font-semibold text-slate-800">
          {t('assignments.grading.viewer.title', `Attempt ${submission.attempt_no}`)}
        </h3>
        <StatusBadge status={submission.status} />
        {submission.is_late && (
          <span
            data-testid="viewer-late"
            className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-800"
          >
            {t('assignments.grading.viewer.late', 'Late')}
          </span>
        )}
        <span className="text-xs text-slate-500">
          {t('assignments.grading.viewer.submittedAt', `Submitted ${formatDate(submission.submitted_at)}`)}
        </span>
      </header>

      {submission.text_response && (
        <div data-testid="viewer-text">
          <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
            {t('assignments.grading.viewer.response', 'Response')}
          </h4>
          <p className="whitespace-pre-wrap rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
            {submission.text_response}
          </p>
        </div>
      )}

      {submission.external_url && (
        <div data-testid="viewer-url">
          <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
            {t('assignments.grading.viewer.url', 'Submitted link')}
          </h4>
          <a
            href={submission.external_url}
            target="_blank"
            rel="noopener noreferrer"
            className="break-all text-sm text-blue-600 hover:underline"
          >
            {submission.external_url}
          </a>
        </div>
      )}

      <div>
        <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
          {t('assignments.grading.viewer.files', 'Files')}
        </h4>
        <SubmissionFileList files={submission.files} resolveUrl={resolveFileUrl} />
      </div>
    </section>
  );
}
