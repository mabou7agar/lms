import { describe, expect, it, vi } from 'vitest';

// gradebook-api imports the shared client at module load — stub it.
vi.mock('@/lib/api/client', () => ({
  api: { get: vi.fn(), post: vi.fn(), data: (x: unknown) => x },
  ApiRequestError: class ApiRequestError extends Error {},
}));

import {
  buildGradebookQuery,
  cellStatus,
  deriveColumns,
  gradebookCsvFilename,
  gradebookExportPath,
  gradebookPath,
  type GradebookCell,
  type GradebookRow,
} from '@/lib/gradebook/gradebook-api';

function cell(overrides: Partial<GradebookCell>): GradebookCell {
  return {
    type: 'assignment',
    ref: 'asg',
    title: 'A',
    status: 'graded',
    score: 5,
    max: 10,
    percent: 50,
    passed: true,
    is_late: false,
    released: true,
    missing: false,
    ...overrides,
  };
}

describe('gradebook-api paths & query', () => {
  it('builds the backend-relative gradebook path with encoded id (no leading slash)', () => {
    expect(gradebookPath('crs 1')).toBe('v1/admin/courses/crs%201/gradebook');
  });

  it('builds the absolute BFF export path', () => {
    expect(gradebookExportPath('crs_1')).toBe('/api/backend/v1/admin/courses/crs_1/gradebook/export');
  });

  it('names the csv download file after the course', () => {
    expect(gradebookCsvFilename('crs_1')).toBe('gradebook-course-crs_1.csv');
  });

  it('serializes only defined query params (only=missing|late)', () => {
    expect(buildGradebookQuery({})).toBe('');
    expect(buildGradebookQuery({ page: 2, per_page: 50, only: 'late' })).toBe('?page=2&per_page=50&only=late');
    expect(buildGradebookQuery({ only: null })).toBe('');
  });
});

describe('deriveColumns', () => {
  it('derives columns from the first non-empty row in cell order', () => {
    const rows: GradebookRow[] = [
      {
        user_id: 1,
        cells: [cell({ type: 'assignment', ref: 'asg_1', title: 'Essay' }), cell({ type: 'quiz', ref: 'qz_1', title: 'Quiz' })],
        summary: { total_columns: 2, missing_count: 0, passed_count: 2, average_percent: 50 },
      },
    ];
    expect(deriveColumns(rows)).toEqual([
      { type: 'assignment', ref: 'asg_1', title: 'Essay', index: 0 },
      { type: 'quiz', ref: 'qz_1', title: 'Quiz', index: 1 },
    ]);
  });

  it('returns no columns for an empty page', () => {
    expect(deriveColumns([])).toEqual([]);
  });
});

describe('cellStatus', () => {
  it('classifies each cell state', () => {
    expect(cellStatus(cell({ missing: true }))).toBe('missing');
    expect(cellStatus(cell({ is_late: true }))).toBe('late');
    expect(cellStatus(cell({ passed: true }))).toBe('passed');
    expect(cellStatus(cell({ passed: false }))).toBe('failed');
    expect(cellStatus(cell({ passed: null, released: false }))).toBe('unreleased');
    expect(cellStatus(cell({ passed: null, released: true, score: 5 }))).toBe('graded');
    expect(cellStatus(cell({ passed: null, released: true, score: null, percent: null }))).toBe('pending');
  });
});
