'use client';

import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';

import { GradebookView } from '@/components/gradebook';
import { GradebookI18nProvider, type GradebookLocale } from '@/lib/gradebook/gradebook-i18n';

/**
 * Instructor gradebook page: /teach/courses/{public_id}/gradebook
 *
 * Client component so it can read the route param and hydrate the module-local
 * i18n provider. Locale is read from the document lang (set by the app shell),
 * defaulting to 'en'; wiring the app-wide locale here is a shared-infra concern.
 */
export default function CourseGradebookPage() {
  const params = useParams<{ public_id: string }>();
  const publicId = Array.isArray(params.public_id) ? params.public_id[0] : params.public_id;

  const [locale, setLocale] = useState<GradebookLocale>('en');
  useEffect(() => {
    const lang = document.documentElement.getAttribute('lang');
    setLocale(lang === 'ar' ? 'ar' : 'en');
  }, []);

  return (
    <GradebookI18nProvider locale={locale}>
      <main className="mx-auto w-full max-w-7xl px-4 py-8">
        <GradebookView publicId={publicId ?? ''} />
      </main>
    </GradebookI18nProvider>
  );
}
