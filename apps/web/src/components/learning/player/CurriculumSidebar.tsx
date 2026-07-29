'use client';

import { Badge, Skeleton } from '@/components/ui';
import {
  isLessonNavigable,
  type RuntimeCurriculum,
  type RuntimeLesson,
} from '@/lib/learning/player-api';
import { useLearningPlayerI18n } from '@/lib/learning/player-i18n';
import { lockReasonText } from './LockedLessonNotice';

export interface CurriculumSidebarProps {
  curriculum?: RuntimeCurriculum;
  isLoading?: boolean;
  activeLessonId?: string | null;
  onSelectLesson: (lessonId: string) => void;
}

/**
 * Module hierarchy with per-lesson state (completed / preview / locked with reason).
 * Locked lessons are rendered as disabled, non-interactive rows carrying their reason;
 * navigable lessons are real <button>s (keyboard + screen-reader friendly).
 */
export function CurriculumSidebar({
  curriculum,
  isLoading,
  activeLessonId,
  onSelectLesson,
}: CurriculumSidebarProps): React.ReactElement {
  const { t } = useLearningPlayerI18n();

  if (isLoading && !curriculum) {
    return (
      <nav aria-label={t('player.curriculum')} data-testid="curriculum-loading">
        <div className="space-y-3 p-3">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-8 w-full" />
          ))}
        </div>
      </nav>
    );
  }

  return (
    <nav aria-label={t('player.curriculum')} data-testid="curriculum-sidebar">
      <ol className="space-y-4 p-3">
        {curriculum?.sections.map((section, sIdx) => (
          <li key={section.id}>
            <h3 className="px-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">
              <span className="me-1">{sIdx + 1}.</span>
              {section.title}
            </h3>
            <ul className="mt-1 space-y-1">
              {section.lessons.map((lesson) => (
                <li key={lesson.id}>
                  <LessonRow
                    lesson={lesson}
                    active={lesson.id === activeLessonId}
                    onSelect={onSelectLesson}
                  />
                </li>
              ))}
            </ul>
          </li>
        ))}
      </ol>
    </nav>
  );
}

function LessonRow({
  lesson,
  active,
  onSelect,
}: {
  lesson: RuntimeLesson;
  active: boolean;
  onSelect: (lessonId: string) => void;
}): React.ReactElement {
  const { t, locale } = useLearningPlayerI18n();
  const navigable = isLessonNavigable(lesson);

  const commonClasses =
    'flex w-full items-start gap-2 rounded-md px-2 py-2 text-start text-sm ' +
    (active ? 'bg-neutral-100 font-medium ' : '');

  const stateBadge = lesson.completed ? (
    <Badge variant="success" data-testid={`lesson-state-${lesson.id}`}>
      {t('player.completed')}
    </Badge>
  ) : lesson.is_preview ? (
    <Badge variant="info">{t('player.preview')}</Badge>
  ) : null;

  if (!navigable) {
    return (
      <div
        className={commonClasses + 'cursor-not-allowed opacity-60'}
        aria-disabled="true"
        data-locked="true"
        data-testid={`lesson-locked-${lesson.id}`}
        title={lockReasonText(t, lesson.lock_reason, lesson.available_at, locale)}
      >
        <span aria-hidden className="mt-0.5">
          🔒
        </span>
        <span className="min-w-0 flex-1">
          <span className="block truncate">{lesson.title}</span>
          <span className="block text-xs text-neutral-500">
            {lockReasonText(t, lesson.lock_reason, lesson.available_at, locale)}
          </span>
          <span className="sr-only">{t('player.lockedAria')}</span>
        </span>
      </div>
    );
  }

  return (
    <button
      type="button"
      className={commonClasses + 'hover:bg-neutral-100'}
      aria-current={active ? 'true' : undefined}
      onClick={() => onSelect(lesson.id)}
      data-testid={`lesson-link-${lesson.id}`}
    >
      <span aria-hidden className="mt-0.5">
        {lesson.completed ? '✓' : '▸'}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate">{lesson.title}</span>
      </span>
      {stateBadge}
    </button>
  );
}
