'use client';

import type { LessonLockReason, RuntimeLesson } from '@/lib/learning/player-api';
import { useLearningPlayerI18n, type TranslateFn } from '@/lib/learning/player-i18n';

/** Resolve a human lock reason. Drip uses the release date when the backend supplies it. */
export function lockReasonText(
  t: TranslateFn,
  reason: LessonLockReason | null,
  availableAt: string | null,
  locale: string,
): string {
  switch (reason) {
    case 'prerequisite_incomplete':
      return t('player.lock.prerequisite_incomplete');
    case 'drip_not_released':
      if (availableAt) {
        const date = safeFormatDate(availableAt, locale);
        if (date) return t('player.lock.drip_not_released.at', { date });
      }
      return t('player.lock.drip_not_released');
    case 'unpublished':
      return t('player.lock.unpublished');
    default:
      return t('player.lock.generic');
  }
}

function safeFormatDate(iso: string, locale: string): string | null {
  const ms = Date.parse(iso);
  if (Number.isNaN(ms)) return null;
  try {
    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar' : 'en', {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(new Date(ms));
  } catch {
    return new Date(ms).toISOString();
  }
}

export function LockedLessonNotice({ lesson }: { lesson: RuntimeLesson }): React.ReactElement {
  const { t, locale } = useLearningPlayerI18n();
  return (
    <div
      role="note"
      className="rounded-lg border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900"
      data-testid="locked-lesson-notice"
    >
      <p className="font-medium">{t('player.locked')}</p>
      <p className="mt-1">{lockReasonText(t, lesson.lock_reason, lesson.available_at, locale)}</p>
    </div>
  );
}
