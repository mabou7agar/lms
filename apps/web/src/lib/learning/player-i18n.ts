'use client';

/**
 * Module-local i18n for the learner course player. Independent of the app-wide
 * i18n so the player ships its own en/ar strings and RTL handling. Usage:
 *
 *   const { t, dir, locale } = useLearningPlayerI18n();
 *   <p>{t('player.progress', { percent: 42 })}</p>
 *
 * Wrap a subtree in <LearningPlayerI18nProvider locale="ar"> to switch language;
 * the integrator passes the app's active locale down from the route.
 */
import { createContext, createElement, useContext, useMemo, type ReactNode } from 'react';

export type PlayerLocale = 'en' | 'ar';

type Dict = Record<string, string>;

const EN: Dict = {
  'player.loading': 'Loading course…',
  'player.curriculum': 'Course content',
  'player.section': 'Section',
  'player.lesson': 'Lesson',
  'player.completed': 'Completed',
  'player.inProgress': 'In progress',
  'player.preview': 'Preview',
  'player.locked': 'Locked',
  'player.lockedAria': 'Locked lesson, not available',
  'player.lock.prerequisite_incomplete': 'Complete the previous lessons to unlock this.',
  'player.lock.drip_not_released': 'This lesson unlocks later.',
  'player.lock.drip_not_released.at': 'This lesson unlocks on {date}.',
  'player.lock.unpublished': 'This lesson is not yet published.',
  'player.lock.generic': 'This lesson is locked.',
  'player.progress': '{percent}% complete',
  'player.progressLessons': '{completed} of {total} lessons complete',
  'player.resume': 'Resume',
  'player.resumeTo': 'Resume: {title}',
  'player.courseComplete': 'Course complete',
  'player.previous': 'Previous',
  'player.next': 'Next',
  'player.markComplete': 'Mark as complete',
  'player.markingComplete': 'Saving…',
  'player.lessonComplete': 'Lesson complete',
  'player.completionBlocked': 'Finish the required activities in this lesson before completing it.',
  'player.video.play': 'Play',
  'player.video.pause': 'Pause',
  'player.video.resumedAt': 'Resumed from {time}',
  'player.video.expired': 'Playback link expired. Reloading…',
  'player.video.unavailable': 'Video is not available right now.',
  'player.audio.unavailable': 'Audio is not available right now.',
  'player.document.open': 'Open document',
  'player.document.download': 'Download',
  'player.assessment.title': 'Assessment',
  'player.assessment.launch': 'Start assessment',
  'player.assignment.title': 'Assignment',
  'player.assignment.launch': 'Open assignment',
  'player.error.title': 'Something went wrong',
  'player.error.curriculum': 'We could not load this course.',
  'player.error.lesson': 'We could not load this lesson.',
  'player.error.retry': 'Try again',
  'player.error.expiredTitle': 'Your access to this course has ended',
  'player.error.expired': 'The access window on this enrolment has closed, so the lessons are no longer available. Renew it to carry on where you left off.',
  'player.openMenu': 'Open course content',
  'player.closeMenu': 'Close',
  'player.blockComplete': 'Mark done',
  'player.blockCompleted': 'Done',
};

const AR: Dict = {
  'player.loading': 'جارٍ تحميل الدورة…',
  'player.curriculum': 'محتوى الدورة',
  'player.section': 'القسم',
  'player.lesson': 'الدرس',
  'player.completed': 'مكتمل',
  'player.inProgress': 'قيد التقدم',
  'player.preview': 'معاينة',
  'player.locked': 'مقفل',
  'player.lockedAria': 'درس مقفل، غير متاح',
  'player.lock.prerequisite_incomplete': 'أكمل الدروس السابقة لفتح هذا الدرس.',
  'player.lock.drip_not_released': 'سيتم فتح هذا الدرس لاحقًا.',
  'player.lock.drip_not_released.at': 'سيتم فتح هذا الدرس في {date}.',
  'player.lock.unpublished': 'لم يتم نشر هذا الدرس بعد.',
  'player.lock.generic': 'هذا الدرس مقفل.',
  'player.progress': 'اكتمل {percent}%',
  'player.progressLessons': 'اكتمل {completed} من {total} درسًا',
  'player.resume': 'استئناف',
  'player.resumeTo': 'استئناف: {title}',
  'player.courseComplete': 'اكتملت الدورة',
  'player.previous': 'السابق',
  'player.next': 'التالي',
  'player.markComplete': 'وضع علامة كمكتمل',
  'player.markingComplete': 'جارٍ الحفظ…',
  'player.lessonComplete': 'اكتمل الدرس',
  'player.completionBlocked': 'أكمل الأنشطة المطلوبة في هذا الدرس قبل إتمامه.',
  'player.video.play': 'تشغيل',
  'player.video.pause': 'إيقاف مؤقت',
  'player.video.resumedAt': 'تم الاستئناف من {time}',
  'player.video.expired': 'انتهت صلاحية رابط التشغيل. جارٍ إعادة التحميل…',
  'player.video.unavailable': 'الفيديو غير متاح حاليًا.',
  'player.audio.unavailable': 'الصوت غير متاح حاليًا.',
  'player.document.open': 'فتح المستند',
  'player.document.download': 'تنزيل',
  'player.assessment.title': 'التقييم',
  'player.assessment.launch': 'بدء التقييم',
  'player.assignment.title': 'الواجب',
  'player.assignment.launch': 'فتح الواجب',
  'player.error.title': 'حدث خطأ ما',
  'player.error.curriculum': 'تعذر تحميل هذه الدورة.',
  'player.error.lesson': 'تعذر تحميل هذا الدرس.',
  'player.error.retry': 'إعادة المحاولة',
  'player.error.expiredTitle': 'انتهى وصولك إلى هذه الدورة',
  'player.error.expired': 'انتهت مدة الوصول لهذا التسجيل، لذا لم تعد الدروس متاحة. جدّدها لمتابعة ما بدأته.',
  'player.openMenu': 'فتح محتوى الدورة',
  'player.closeMenu': 'إغلاق',
  'player.blockComplete': 'وضع علامة كمنجز',
  'player.blockCompleted': 'منجز',
};

const DICTS: Record<PlayerLocale, Dict> = { en: EN, ar: AR };

export type TranslateVars = Record<string, string | number>;
export type TranslateFn = (key: string, vars?: TranslateVars) => string;

export interface LearningPlayerI18n {
  locale: PlayerLocale;
  dir: 'ltr' | 'rtl';
  t: TranslateFn;
}

const LearningPlayerI18nContext = createContext<PlayerLocale>('en');

export function LearningPlayerI18nProvider(props: {
  locale?: string | null;
  children: ReactNode;
}): ReactNode {
  const locale: PlayerLocale = props.locale === 'ar' ? 'ar' : 'en';
  return createElement(LearningPlayerI18nContext.Provider, { value: locale }, props.children);
}

function interpolate(template: string, vars?: TranslateVars): string {
  if (!vars) return template;
  return template.replace(/\{(\w+)\}/g, (match, name: string) =>
    Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : match,
  );
}

export function useLearningPlayerI18n(): LearningPlayerI18n {
  const locale = useContext(LearningPlayerI18nContext);
  return useMemo<LearningPlayerI18n>(() => {
    const dict = DICTS[locale] ?? EN;
    const t: TranslateFn = (key, vars) => interpolate(dict[key] ?? EN[key] ?? key, vars);
    return { locale, dir: locale === 'ar' ? 'rtl' : 'ltr', t };
  }, [locale]);
}
