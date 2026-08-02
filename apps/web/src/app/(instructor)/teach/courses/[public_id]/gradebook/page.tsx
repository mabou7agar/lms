'use client';

import { useParams } from 'next/navigation';

import { GradebookView } from '@/components/gradebook';
import { GradebookI18nProvider, type GradebookLocale } from '@/lib/gradebook/gradebook-i18n';
import { useI18n } from '@/lib/i18n/i18n-context';

/**
 * Instructor gradebook page: /teach/courses/{public_id}/gradebook
 *
 * Client component so it can read the route param and hydrate the module-local
 * i18n provider. Locale is derived from the app-wide i18n context (driven by the same
 * locale cookie that sets <html lang>), so it is correct on the first render — no post-mount
 * effect and no en→ar flash.
 */
export default function CourseGradebookPage() {
  const params = useParams<{ public_id: string }>();
  const publicId = Array.isArray(params.public_id) ? params.public_id[0] : params.public_id;

  const { locale: appLocale } = useI18n();
  const locale: GradebookLocale = appLocale === 'ar' ? 'ar' : 'en';

  return (
    <GradebookI18nProvider locale={locale}>
      <main className="mx-auto w-full max-w-7xl px-4 py-8">
        <GradebookView publicId={publicId ?? ''} />
      </main>
    </GradebookI18nProvider>
  );
}
