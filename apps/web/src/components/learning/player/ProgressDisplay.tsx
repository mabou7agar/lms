'use client';

import { CheckCircle2, PlayCircle } from 'lucide-react';
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
    <section
      aria-label={t('player.progress', { percent })}
      data-testid="progress-display"
      className="rounded-2xl border border-border/70 bg-card p-5"
    >
      <div className="flex items-center justify-between gap-4">
        <div className="min-w-0">
          <p className="flex items-center gap-2 text-sm font-medium">
            {courseCompleted ? (
              <>
                <CheckCircle2 aria-hidden className="size-4 text-primary" />
                {t('player.courseComplete')}
              </>
            ) : (
              t('player.progress', { percent })
            )}
          </p>
          {typeof completedLessons === 'number' && typeof totalLessons === 'number' ? (
            <p className="mt-0.5 text-xs text-muted-foreground">
              {t('player.progressLessons', { completed: completedLessons, total: totalLessons })}
            </p>
          ) : null}
        </div>
        <div className="flex items-center gap-3">
          <span className="font-serif text-2xl font-bold tabular-nums text-copper">{percent}%</span>
          {resumeLessonId && onResume ? (
            <Button
              variant="primary"
              onClick={() => onResume(resumeLessonId)}
              data-testid="resume-button"
            >
              <PlayCircle aria-hidden className="size-4" />
              {resumeTitle ? t('player.resumeTo', { title: resumeTitle }) : t('player.resume')}
            </Button>
          ) : null}
        </div>
      </div>
      <div
        className="mt-3 h-2.5 w-full overflow-hidden rounded-full bg-surface"
        role="progressbar"
        aria-valuenow={percent}
        aria-valuemin={0}
        aria-valuemax={100}
      >
        <div
          className="h-full rounded-full bg-gradient-to-r from-copper to-primary transition-[width] duration-500 motion-reduce:transition-none"
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
