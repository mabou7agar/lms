/**
 * Module-local i18n for the gradebook (en / ar, RTL-aware). Self-contained: a
 * lightweight context supplies the active locale, defaulting to 'en'. The page
 * wraps its subtree in <GradebookI18nProvider locale={...}> to switch to 'ar';
 * every component reads via useGradebookI18n().
 *
 * RTL matters here because the table scrolls horizontally with a pinned learner
 * column — components use CSS logical properties (inline-start/inline-end) and
 * read `dir` from this hook rather than hard-coding left/right.
 */

'use client';

import { createContext, createElement, useContext, useMemo, type ReactNode } from 'react';

export type GradebookLocale = 'en' | 'ar';

type Vars = Record<string, string | number>;

const MESSAGES: Record<GradebookLocale, Record<string, string>> = {
  en: {
    'page.title': 'Gradebook',
    'page.subtitle': 'Grades for every learner across assignments and quizzes.',
    'column.learner': 'Learner',
    'column.overall': 'Overall',
    'learner.label': 'Learner #{id}',
    'colType.assignment': 'Assignment',
    'colType.quiz': 'Quiz',
    'filter.all': 'All learners',
    'filter.missing': 'Has missing work',
    'filter.late': 'Has late work',
    'filter.label': 'Filter',
    'perPage.label': 'Rows per page',
    'export.action': 'Export CSV',
    'export.pending': 'Exporting…',
    'export.success': 'Gradebook CSV export started.',
    'export.error': 'Could not export the gradebook. Please try again.',
    'status.missing': 'Missing',
    'status.late': 'Late',
    'status.passed': 'Passed',
    'status.failed': 'Failed',
    'status.unreleased': 'Not released',
    'status.graded': 'Graded',
    'status.pending': 'Pending',
    'status.draft': 'Draft',
    'status.submitted': 'Submitted',
    'status.under_review': 'Under review',
    'status.changes_requested': 'Changes requested',
    'status.returned': 'Returned',
    'status.cancelled': 'Cancelled',
    'status.completed': 'Completed',
    'status.in_progress': 'In progress',
    'cell.score': '{score} / {max}',
    'cell.percent': '{percent}%',
    'cell.noScore': '—',
    'summary.missing': '{count} missing',
    'summary.passed': '{count} passed',
    'summary.average': 'Avg {percent}%',
    'summary.noAverage': 'No grades yet',
    'pagination.summary': 'Showing {from}–{to} of {total} learners',
    'pagination.prev': 'Previous',
    'pagination.next': 'Next',
    'pagination.page': 'Page {page} of {last}',
    'drawer.title': 'Learner #{id}',
    'drawer.close': 'Close',
    'drawer.overall': 'Overall grade',
    'drawer.columns': 'Per-item breakdown',
    'drawer.open': 'View learner detail',
    'state.loading': 'Loading gradebook…',
    'state.empty.title': 'No learners yet',
    'state.empty.body': 'Once learners enrol or submit work, they will appear here.',
    'state.error.title': 'Could not load the gradebook',
    'state.error.body': 'Something went wrong loading grades. Please try again.',
    'state.retry': 'Retry',
    'gate.title': 'Instructors only',
    'gate.body': 'You do not have permission to view this gradebook.',
  },
  ar: {
    'page.title': 'سجل الدرجات',
    'page.subtitle': 'درجات كل متعلم عبر الواجبات والاختبارات.',
    'column.learner': 'المتعلم',
    'column.overall': 'الإجمالي',
    'learner.label': 'المتعلم رقم {id}',
    'colType.assignment': 'واجب',
    'colType.quiz': 'اختبار',
    'filter.all': 'كل المتعلمين',
    'filter.missing': 'لديه أعمال ناقصة',
    'filter.late': 'لديه أعمال متأخرة',
    'filter.label': 'تصفية',
    'perPage.label': 'صفوف لكل صفحة',
    'export.action': 'تصدير CSV',
    'export.pending': 'جارٍ التصدير…',
    'export.success': 'بدأ تصدير ملف الدرجات.',
    'export.error': 'تعذّر تصدير سجل الدرجات. حاول مرة أخرى.',
    'status.missing': 'ناقص',
    'status.late': 'متأخر',
    'status.passed': 'ناجح',
    'status.failed': 'راسب',
    'status.unreleased': 'غير منشور',
    'status.graded': 'تم التقييم',
    'status.pending': 'قيد الانتظار',
    'status.draft': 'مسودة',
    'status.submitted': 'تم التسليم',
    'status.under_review': 'قيد المراجعة',
    'status.changes_requested': 'مطلوب تعديلات',
    'status.returned': 'مُعاد',
    'status.cancelled': 'ملغى',
    'status.completed': 'مكتمل',
    'status.in_progress': 'قيد التقدم',
    'cell.score': '{score} / {max}',
    'cell.percent': '{percent}٪',
    'cell.noScore': '—',
    'summary.missing': '{count} ناقص',
    'summary.passed': '{count} ناجح',
    'summary.average': 'المتوسط {percent}٪',
    'summary.noAverage': 'لا درجات بعد',
    'pagination.summary': 'عرض {from}–{to} من {total} متعلم',
    'pagination.prev': 'السابق',
    'pagination.next': 'التالي',
    'pagination.page': 'صفحة {page} من {last}',
    'drawer.title': 'المتعلم رقم {id}',
    'drawer.close': 'إغلاق',
    'drawer.overall': 'الدرجة الإجمالية',
    'drawer.columns': 'تفصيل لكل عنصر',
    'drawer.open': 'عرض تفاصيل المتعلم',
    'state.loading': 'جارٍ تحميل سجل الدرجات…',
    'state.empty.title': 'لا يوجد متعلمون بعد',
    'state.empty.body': 'سيظهر المتعلمون هنا بمجرد التحاقهم أو تسليمهم للأعمال.',
    'state.error.title': 'تعذّر تحميل سجل الدرجات',
    'state.error.body': 'حدث خطأ أثناء تحميل الدرجات. حاول مرة أخرى.',
    'state.retry': 'إعادة المحاولة',
    'gate.title': 'للمدرّبين فقط',
    'gate.body': 'ليس لديك إذن لعرض سجل الدرجات هذا.',
  },
};

export type GradebookTranslate = (key: string, vars?: Vars) => string;

export interface GradebookI18n {
  locale: GradebookLocale;
  dir: 'ltr' | 'rtl';
  t: GradebookTranslate;
}

const GradebookLocaleContext = createContext<GradebookLocale>('en');

export interface GradebookI18nProviderProps {
  locale?: GradebookLocale;
  children: ReactNode;
}

export function GradebookI18nProvider({ locale = 'en', children }: GradebookI18nProviderProps): ReactNode {
  return createElement(GradebookLocaleContext.Provider, { value: locale }, children);
}

function interpolate(template: string, vars?: Vars): string {
  if (!vars) return template;
  return template.replace(/\{(\w+)\}/g, (match, name: string) =>
    Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : match,
  );
}

export function makeTranslate(locale: GradebookLocale): GradebookTranslate {
  const dict = MESSAGES[locale] ?? MESSAGES.en;
  return (key, vars) => interpolate(dict[key] ?? MESSAGES.en[key] ?? key, vars);
}

/**
 * Translate a raw backend status value (SubmissionStatus / attempt status) into
 * a localized label, falling back to a humanized form for unknown values.
 */
export function translateStatus(t: GradebookTranslate, status: string): string {
  const key = `status.${status}`;
  const label = t(key);
  if (label !== key) return label;
  return status.replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function useGradebookI18n(): GradebookI18n {
  const locale = useContext(GradebookLocaleContext);
  return useMemo(
    () => ({
      locale,
      dir: locale === 'ar' ? 'rtl' : 'ltr',
      t: makeTranslate(locale),
    }),
    [locale],
  );
}
