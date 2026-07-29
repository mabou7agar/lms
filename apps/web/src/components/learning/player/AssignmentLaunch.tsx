'use client';

import { Badge, Button } from '@/components/ui';
import type { LessonContent } from '@/lib/learning/player-api';
import { useLearningPlayerI18n } from '@/lib/learning/player-i18n';

/**
 * Assignment launch entry point. Entry point only — the assignment runtime is
 * owned elsewhere; `onLaunch` receives the assignment public id.
 */
export function AssignmentLaunch({
  assignment,
  onLaunch,
}: {
  assignment: NonNullable<LessonContent['assignment']>;
  onLaunch: (assignmentId: string) => void;
}): React.ReactElement {
  const { t } = useLearningPlayerI18n();
  return (
    <div
      className="flex items-center justify-between gap-4 rounded-lg border border-neutral-200 p-4"
      data-testid="assignment-launch"
    >
      <div className="min-w-0">
        <p className="text-xs font-semibold uppercase tracking-wide text-neutral-500">
          {t('player.assignment.title')}
        </p>
        <p className="truncate font-medium">{assignment.title}</p>
        {assignment.status ? <Badge variant="info">{assignment.status}</Badge> : null}
      </div>
      <Button variant="primary" onClick={() => onLaunch(assignment.id)}>
        {t('player.assignment.launch')}
      </Button>
    </div>
  );
}
