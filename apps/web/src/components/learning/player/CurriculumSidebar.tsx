'use client';

import { Check, Play, Lock, Circle } from 'lucide-react';
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
    <nav
      aria-label={t('player.curriculum')}
      data-testid="curriculum-sidebar"
      className="overflow-hidden rounded-2xl border border-border/70 bg-card"
    >
      <div className="border-b border-border/60 px-4 py-3">
        <h2 className="font-serif text-base font-semibold">{t('player.curriculum')}</h2>
      </div>
      <ol className="space-y-5 p-3">
        {curriculum?.sections.map((section, sIdx) => (
          <li key={section.id}>
            <h3 className="flex items-center gap-2 px-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              <span className="grid size-5 shrink-0 place-items-center rounded-md bg-copper/10 font-serif text-[0.7rem] text-copper">
                {sIdx + 1}
              </span>
              <span className="min-w-0 truncate">{section.title}</span>
            </h3>
            <ul className="mt-1.5 space-y-0.5">
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
    'group relative flex w-full items-start gap-2.5 rounded-xl px-2.5 py-2 text-start text-sm transition-colors ';
  const activeClasses = active ? 'bg-copper/[0.08] font-medium text-foreground ' : 'text-muted-foreground ';

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
        className={commonClasses + 'cursor-not-allowed opacity-70'}
        aria-disabled="true"
        data-locked="true"
        data-testid={`lesson-locked-${lesson.id}`}
        title={lockReasonText(t, lesson.lock_reason, lesson.available_at, locale)}
      >
        <Lock aria-hidden className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
        <span className="min-w-0 flex-1">
          <span className="block truncate">{lesson.title}</span>
          <span className="block text-xs text-muted-foreground">
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
      className={commonClasses + activeClasses + 'hover:bg-accent/60 hover:text-foreground'}
      aria-current={active ? 'true' : undefined}
      onClick={() => onSelect(lesson.id)}
      data-testid={`lesson-link-${lesson.id}`}
    >
      <span
        className="absolute inset-y-2 start-0 w-[3px] rounded-full bg-copper transition-opacity"
        style={{ opacity: active ? 1 : 0 }}
        aria-hidden
      />
      {lesson.completed ? (
        <Check aria-hidden className="mt-0.5 size-4 shrink-0 text-primary" />
      ) : active ? (
        <Play aria-hidden className="mt-0.5 size-4 shrink-0 text-copper" />
      ) : (
        <Circle aria-hidden className="mt-0.5 size-4 shrink-0 text-border" />
      )}
      <span className="min-w-0 flex-1">
        <span className="block truncate">{lesson.title}</span>
      </span>
      {stateBadge}
    </button>
  );
}
