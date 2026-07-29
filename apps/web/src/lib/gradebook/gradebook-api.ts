/**
 * Gradebook API surface. Mirrors the frozen backend contract in
 * app/Domains/Assessment (GradebookService::page / ::toCsv, GradebookController,
 * GradebookQueryRequest, routes/assignments.php).
 *
 * Endpoints (all auth:sanctum, instructor-only, BFF-proxied under /api/backend):
 *   GET v1/admin/courses/{course}/gradebook          -> { data, meta, links } of GradebookRow
 *   GET v1/admin/courses/{course}/gradebook/export    -> streamed text/csv download
 *
 * `{course}` is the course public_id. Query filter `only` is 'missing' | 'late'
 * (nullable); pagination is per_page (1..100, default 25) + page (>=1, default 1).
 *
 * The paginated response carries ONLY rows — column metadata is not a separate
 * field, so headers are derived from any row's `cells` (constant across pages,
 * assignments then quizzes, in service order). See deriveColumns().
 */

import { api } from '@/lib/api/client';

export type GradebookCellType = 'assignment' | 'quiz';

/**
 * One gradebook cell. Shape is verbatim from GradebookService::assignmentCell /
 * ::quizCell. Note: `max` is present on assignment cells only; quiz cells omit it.
 */
export interface GradebookCell {
  type: GradebookCellType;
  /** column public_id (assignment or assessment/quiz). */
  ref: string;
  title: string;
  /** SubmissionStatus value (assignment) or attempt status (quiz); null when missing. */
  status: string | null;
  score: number | null;
  /** assignment cells only. */
  max?: number;
  percent: number | null;
  passed: boolean | null;
  is_late: boolean;
  released: boolean;
  missing: boolean;
}

export interface GradebookRowSummary {
  total_columns: number;
  missing_count: number;
  passed_count: number;
  average_percent: number | null;
}

export interface GradebookRow {
  user_id: number;
  cells: GradebookCell[];
  summary: GradebookRowSummary;
}

/** A derived column header (constant across all rows/pages). */
export interface GradebookColumn {
  type: GradebookCellType;
  ref: string;
  title: string;
  /** column index within a row's `cells` array. */
  index: number;
}

export interface PaginationMeta {
  current_page: number;
  from: number | null;
  last_page: number;
  per_page: number;
  to: number | null;
  total: number;
}

export interface PaginationLinks {
  first: string | null;
  last: string | null;
  prev: string | null;
  next: string | null;
}

export interface Paginated<T> {
  data: T[];
  meta: PaginationMeta;
  links: PaginationLinks;
}

export type GradebookOnlyFilter = 'missing' | 'late';

export interface GradebookQuery {
  page?: number;
  per_page?: number;
  only?: GradebookOnlyFilter | null;
}

export const GRADEBOOK_DEFAULT_PER_PAGE = 25;

const BFF_BASE = '/api/backend';

/**
 * Backend-relative gradebook path — NO leading slash, matching the house
 * convention (`api.get('v1/admin/...')` in lib/assignments/assignments-api.ts).
 */
export function gradebookPath(publicId: string): string {
  return `v1/admin/courses/${encodeURIComponent(publicId)}/gradebook`;
}

/** Absolute BFF export URL (used for a direct blob fetch / download). */
export function gradebookExportPath(publicId: string): string {
  return `${BFF_BASE}/${gradebookPath(publicId)}/export`;
}

export function buildGradebookQuery(query: GradebookQuery): string {
  const params = new URLSearchParams();
  if (query.page != null) params.set('page', String(query.page));
  if (query.per_page != null) params.set('per_page', String(query.per_page));
  if (query.only) params.set('only', query.only);
  const qs = params.toString();
  return qs ? `?${qs}` : '';
}

/** Fetch one page of gradebook rows. Throws ApiRequestError on failure. */
export async function fetchGradebook(
  publicId: string,
  query: GradebookQuery = {},
): Promise<Paginated<GradebookRow>> {
  return api.get<Paginated<GradebookRow>>(`${gradebookPath(publicId)}${buildGradebookQuery(query)}`);
}

/**
 * Trigger the backend CSV export. Hits the streamed download endpoint directly
 * (BFF forwards the session), returning the CSV blob for the caller to save.
 */
export async function fetchGradebookCsv(publicId: string): Promise<Blob> {
  const res = await fetch(gradebookExportPath(publicId), {
    method: 'GET',
    credentials: 'include',
    headers: { Accept: 'text/csv' },
  });
  if (!res.ok) {
    throw new Error(`gradebook-export-failed:${res.status}`);
  }
  return res.blob();
}

export function gradebookCsvFilename(publicId: string): string {
  return `gradebook-course-${publicId}.csv`;
}

/** Save a blob to disk via a transient object URL. No-op if DOM is unavailable. */
export function triggerBlobDownload(blob: Blob, filename: string): void {
  if (typeof document === 'undefined' || typeof URL.createObjectURL !== 'function') {
    return;
  }
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(url);
}

/**
 * Derive column headers from a page of rows. Columns are constant across pages
 * (every learner carries one cell per column, in the same order), so the first
 * non-empty row is authoritative. Returns [] for an empty page.
 */
export function deriveColumns(rows: readonly GradebookRow[]): GradebookColumn[] {
  const first = rows.find((row) => row.cells.length > 0);
  if (!first) {
    return [];
  }
  return first.cells.map((cell, index) => ({
    type: cell.type,
    ref: cell.ref,
    title: cell.title,
    index,
  }));
}

/** Semantic status of a single cell, for badge rendering. */
export type CellStatus = 'missing' | 'late' | 'passed' | 'failed' | 'unreleased' | 'graded' | 'pending';

export function cellStatus(cell: GradebookCell): CellStatus {
  if (cell.missing) return 'missing';
  if (cell.is_late) return 'late';
  if (cell.passed === true) return 'passed';
  if (cell.passed === false) return 'failed';
  if (!cell.released) return 'unreleased';
  if (cell.score !== null || cell.percent !== null) return 'graded';
  return 'pending';
}
