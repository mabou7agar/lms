import type { ReactElement, ReactNode } from 'react';
import { render, type RenderResult } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import {
  GradebookI18nProvider,
  type GradebookLocale,
} from '@/lib/gradebook/gradebook-i18n';
import type {
  GradebookCell,
  GradebookRow,
  Paginated,
} from '@/lib/gradebook/gradebook-api';

/**
 * Local mirror of the shared `renderWithI18n` (see SHARED-INFRA NEEDS). Wraps in
 * the module-local gradebook i18n provider + a fresh React Query client so tests
 * are deterministic and isolated from app-wide providers.
 */
export function renderWithI18n(ui: ReactElement, locale: GradebookLocale = 'en'): RenderResult {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>
      <GradebookI18nProvider locale={locale}>{children}</GradebookI18nProvider>
    </QueryClientProvider>
  );
  return render(ui, { wrapper: Wrapper });
}

export function assignmentCell(overrides: Partial<GradebookCell> = {}): GradebookCell {
  return {
    type: 'assignment',
    ref: 'asg_1',
    title: 'Essay 1',
    status: 'graded',
    score: 8,
    max: 10,
    percent: 80,
    passed: true,
    is_late: false,
    released: true,
    missing: false,
    ...overrides,
  };
}

export function quizCell(overrides: Partial<GradebookCell> = {}): GradebookCell {
  return {
    type: 'quiz',
    ref: 'qz_1',
    title: 'Quiz 1',
    status: 'completed',
    score: 5,
    percent: 50,
    passed: false,
    is_late: false,
    released: true,
    missing: false,
    ...overrides,
  };
}

export function makeRow(userId: number, cells: GradebookCell[]): GradebookRow {
  const missing = cells.filter((c) => c.missing).length;
  const passed = cells.filter((c) => c.passed === true).length;
  const percents = cells.filter((c) => !c.missing && c.percent !== null).map((c) => c.percent as number);
  const average = percents.length === 0 ? null : Math.round((percents.reduce((a, b) => a + b, 0) / percents.length) * 100) / 100;
  return {
    user_id: userId,
    cells,
    summary: {
      total_columns: cells.length,
      missing_count: missing,
      passed_count: passed,
      average_percent: average,
    },
  };
}

export function makePage(rows: GradebookRow[], overrides: Partial<Paginated<GradebookRow>['meta']> = {}): Paginated<GradebookRow> {
  const meta = {
    current_page: 1,
    from: rows.length ? 1 : null,
    last_page: 3,
    per_page: 25,
    to: rows.length || null,
    total: 60,
    ...overrides,
  };
  return {
    data: rows,
    meta,
    links: { first: '?page=1', last: '?page=3', prev: null, next: '?page=2' },
  };
}
