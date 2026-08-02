'use client';

import { useAssignmentsI18n } from '@/lib/assignments/assignments-i18n';
import type { SubmissionStatus } from './types';

const STYLES: Record<string, string> = {
  draft: 'bg-muted text-muted-foreground',
  submitted: 'bg-primary/10 text-primary',
  under_review: 'bg-primary/10 text-primary',
  changes_requested: 'bg-gold/15 text-foreground',
  graded: 'bg-copper/10 text-copper',
  released: 'bg-primary/10 text-primary',
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
  const cls = STYLES[status] ?? 'bg-muted text-muted-foreground';
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
