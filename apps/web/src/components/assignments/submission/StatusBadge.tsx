'use client';

import { useAssignmentsI18n } from '@/lib/assignments/assignments-i18n';
import type { SubmissionStatus } from './types';

const STYLES: Record<string, string> = {
  draft: 'bg-slate-100 text-slate-700',
  submitted: 'bg-blue-100 text-blue-700',
  under_review: 'bg-indigo-100 text-indigo-700',
  changes_requested: 'bg-amber-100 text-amber-800',
  graded: 'bg-violet-100 text-violet-700',
  released: 'bg-green-100 text-green-700',
};

const DEFAULT_LABELS: Record<string, string> = {
  draft: 'Draft',
  submitted: 'Submitted',
  under_review: 'Under review',
  changes_requested: 'Changes requested',
  graded: 'Graded',
  released: 'Released',
};

export function StatusBadge({ status }: { status: SubmissionStatus }) {
  const { t } = useAssignmentsI18n();
  const cls = STYLES[status] ?? 'bg-slate-100 text-slate-700';
  const label = t(`assignments.status.${status}`, DEFAULT_LABELS[status] ?? status);
  return (
    <span
      data-testid="status-badge"
      data-status={status}
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${cls}`}
    >
      {label}
    </span>
  );
}
