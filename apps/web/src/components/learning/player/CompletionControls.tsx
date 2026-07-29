'use client';

import { useState } from 'react';

import { ApiRequestError } from '@/lib/api/client';
import { Badge, Button } from '@/components/ui';
import { useCompleteLesson } from '@/lib/learning/player-hooks';
import { useLearningPlayerI18n } from '@/lib/learning/player-i18n';

/** Pull a stable backend error code off an unknown error, if present. */
export function errorCodeOf(error: unknown): string | null {
  if (error instanceof ApiRequestError) {
    const e = error as unknown as { code?: string; errorCode?: string };
    return e.code ?? e.errorCode ?? null;
  }
  if (error && typeof error === 'object') {
    const e = error as { code?: string; errorCode?: string };
    return e.code ?? e.errorCode ?? null;
  }
  return null;
}

/**
 * Lesson completion control. Completion is SERVER-authoritative: the button
 * calls POST /lessons/{lesson}/complete and reflects the returned status. A
 * 422 LEARNING_COMPLETION_BLOCKED surfaces the "finish required activities"
 * message rather than marking the lesson done.
 */
export function CompletionControls({
  courseId,
  lessonId,
  completed,
  onCompleted,
}: {
  courseId: string;
  lessonId: string;
  /** Server-reported completion for this lesson (from curriculum). */
  completed: boolean;
  onCompleted?: (courseProgressPercentage: number) => void;
}): React.ReactElement {
  const { t } = useLearningPlayerI18n();
  const mutation = useCompleteLesson(courseId);
  const [blocked, setBlocked] = useState(false);

  if (completed) {
    return (
      <div data-testid="lesson-completed">
        <Badge variant="success">{t('player.lessonComplete')}</Badge>
      </div>
    );
  }

  const onClick = () => {
    setBlocked(false);
    mutation.mutate(lessonId, {
      onSuccess: (res) => onCompleted?.(res.course_progress_percentage),
      onError: (err) => {
        if (errorCodeOf(err) === 'LEARNING_COMPLETION_BLOCKED') setBlocked(true);
      },
    });
  };

  return (
    <div className="space-y-2" data-testid="completion-controls">
      <Button variant="primary" onClick={onClick} disabled={mutation.isPending}>
        {mutation.isPending ? t('player.markingComplete') : t('player.markComplete')}
      </Button>
      {blocked ? (
        <p role="alert" className="text-sm text-amber-700" data-testid="completion-blocked">
          {t('player.completionBlocked')}
        </p>
      ) : null}
    </div>
  );
}
