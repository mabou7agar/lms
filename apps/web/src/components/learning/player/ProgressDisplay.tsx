'use client';

import { Button } from '@/components/ui';
import { useLearningPlayerI18n } from '@/lib/learning/player-i18n';

export interface ProgressDisplayProps {
  progressPercentage: number;
  completedLessons?: number;
  totalLessons?: number;
  courseCompleted?: boolean;
  /** Resume target (from launch/summary/resume). */
  resumeLessonId?: string | null;
  resumeTitle?: string | null;
  onResume?: (lessonId: string) => void;
}

/**
 * Course progress bar + resume affordance. Percentage is server-authoritative
 * (from the enrollment/summary payload) — never computed client-side.
 */
export function ProgressDisplay({
  progressPercentage,
  completedLessons,
  totalLessons,
  courseCompleted,
  resumeLessonId,
  resumeTitle,
  onResume,
}: ProgressDisplayProps): React.ReactElement {
  const { t } = useLearningPlayerI18n();
  const percent = clampPercent(progressPercentage);

  return (
    <section aria-label={t('player.progress', { percent })} data-testid="progress-display">
      <div className="flex items-center justify-between gap-4">
        <div className="min-w-0">
          <p className="text-sm font-medium">
            {courseCompleted ? t('player.courseComplete') : t('player.progress', { percent })}
          </p>
          {typeof completedLessons === 'number' && typeof totalLessons === 'number' ? (
            <p className="text-xs text-neutral-500">
              {t('player.progressLessons', { completed: completedLessons, total: totalLessons })}
            </p>
          ) : null}
        </div>
        {resumeLessonId && onResume ? (
          <Button
            variant="primary"
            onClick={() => onResume(resumeLessonId)}
            data-testid="resume-button"
          >
            {resumeTitle ? t('player.resumeTo', { title: resumeTitle }) : t('player.resume')}
          </Button>
        ) : null}
      </div>
      <div
        className="mt-2 h-2 w-full overflow-hidden rounded-full bg-neutral-200"
        role="progressbar"
        aria-valuenow={percent}
        aria-valuemin={0}
        aria-valuemax={100}
      >
        <div
          className="h-full rounded-full bg-emerald-500 transition-[width] motion-reduce:transition-none"
          style={{ width: `${percent}%` }}
        />
      </div>
    </section>
  );
}

function clampPercent(value: number): number {
  if (!Number.isFinite(value)) return 0;
  return Math.max(0, Math.min(100, Math.round(value)));
}
