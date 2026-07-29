'use client';

import { Button } from '@/components/ui';
import {
  flattenLessons,
  nextLesson,
  previousLesson,
  type RuntimeCurriculum,
} from '@/lib/learning/player-api';
import { useLearningPlayerI18n } from '@/lib/learning/player-i18n';

/**
 * Previous / next navigation across the flattened curriculum. Skips locked
 * lessons (they are non-navigable); a disabled button means there is no
 * navigable neighbour in that direction. RTL is handled by logical properties.
 */
export function LessonNav({
  curriculum,
  currentLessonId,
  onNavigate,
}: {
  curriculum?: RuntimeCurriculum;
  currentLessonId: string;
  onNavigate: (lessonId: string) => void;
}): React.ReactElement {
  const { t } = useLearningPlayerI18n();
  const lessons = flattenLessons(curriculum);
  const prev = previousLesson(lessons, currentLessonId);
  const next = nextLesson(lessons, currentLessonId);

  return (
    <nav
      className="flex items-center justify-between gap-2"
      aria-label={`${t('player.previous')} / ${t('player.next')}`}
      data-testid="lesson-nav"
    >
      <Button
        variant="secondary"
        disabled={!prev}
        onClick={() => prev && onNavigate(prev.id)}
        data-testid="nav-previous"
      >
        <span aria-hidden className="rtl:rotate-180">
          ←
        </span>{' '}
        {t('player.previous')}
      </Button>
      <Button
        variant="secondary"
        disabled={!next}
        onClick={() => next && onNavigate(next.id)}
        data-testid="nav-next"
      >
        {t('player.next')}{' '}
        <span aria-hidden className="rtl:rotate-180">
          →
        </span>
      </Button>
    </nav>
  );
}
