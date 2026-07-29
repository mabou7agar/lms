'use client';

import { Badge, Button } from '@/components/ui';
import type { LessonContent } from '@/lib/learning/player-api';
import { useLearningPlayerI18n } from '@/lib/learning/player-i18n';

/**
 * Assessment launch entry point. This is only the ENTRY POINT into the
 * assessment runtime (owned elsewhere) — clicking `onLaunch` hands off the
 * assessment public id to the integrator-provided handler / route.
 */
export function AssessmentLaunch({
  assessment,
  onLaunch,
}: {
  assessment: NonNullable<LessonContent['assessment']>;
  onLaunch: (assessmentId: string) => void;
}): React.ReactElement {
  const { t } = useLearningPlayerI18n();
  return (
    <div
      className="flex items-center justify-between gap-4 rounded-lg border border-neutral-200 p-4"
      data-testid="assessment-launch"
    >
      <div className="min-w-0">
        <p className="text-xs font-semibold uppercase tracking-wide text-neutral-500">
          {t('player.assessment.title')}
        </p>
        <p className="truncate font-medium">{assessment.title}</p>
        {assessment.status ? <Badge variant="info">{assessment.status}</Badge> : null}
      </div>
      <Button variant="primary" onClick={() => onLaunch(assessment.id)}>
        {t('player.assessment.launch')}
      </Button>
    </div>
  );
}
