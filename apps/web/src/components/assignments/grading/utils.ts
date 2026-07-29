import type { Rubric, RubricResultEntry } from './types';

/** Map of criterionId -> selected levelId. */
export type RubricSelection = Record<string, string>;

export function selectionFromResult(result: RubricResultEntry[] | null | undefined): RubricSelection {
  const out: RubricSelection = {};
  for (const entry of result ?? []) out[entry.criterion_public_id] = entry.level_public_id;
  return out;
}

export function selectionToResult(selection: RubricSelection): RubricResultEntry[] {
  return Object.entries(selection).map(([criterion_public_id, level_public_id]) => ({
    criterion_public_id,
    level_public_id,
  }));
}

export interface ScoreBreakdown {
  /** Sum of selected level points. */
  raw: number;
  /** Sum of criteria max_points across the whole rubric. */
  outOf: number;
  /** raw scaled to `maxGrade` (0 when outOf is 0). */
  scaled: number;
  /** True once every criterion has a selected level. */
  complete: boolean;
  selectedCount: number;
  criterionCount: number;
}

/**
 * Compute a score from a rubric selection against the (immutable) rubric snapshot. The raw score is
 * the sum of selected level points; `scaled` maps that onto the assignment's max grade so a rubric
 * whose total differs from the max grade still yields a sensible number. Backend remains the source
 * of truth — this is a live preview for the grader.
 */
export function computeRubricScore(
  rubric: Rubric | null | undefined,
  selection: RubricSelection,
  maxGrade?: number | null,
): ScoreBreakdown {
  if (!rubric) {
    return { raw: 0, outOf: 0, scaled: 0, complete: false, selectedCount: 0, criterionCount: 0 };
  }
  let raw = 0;
  let outOf = 0;
  let selectedCount = 0;
  for (const criterion of rubric.criteria) {
    outOf += criterion.max_points;
    const levelId = selection[criterion.id];
    if (levelId) {
      const level = criterion.levels.find((l) => l.id === levelId);
      if (level) {
        raw += level.points;
        selectedCount += 1;
      }
    }
  }
  const target = maxGrade ?? rubric.total_points ?? outOf;
  const scaled = outOf > 0 ? Math.round(((raw / outOf) * target + Number.EPSILON) * 100) / 100 : 0;
  return {
    raw,
    outOf,
    scaled,
    complete: selectedCount === rubric.criteria.length && rubric.criteria.length > 0,
    selectedCount,
    criterionCount: rubric.criteria.length,
  };
}

/** True when an error object/response indicates an optimistic-concurrency conflict. */
export function isConflictError(err: unknown): boolean {
  if (!err || typeof err !== 'object') return false;
  const e = err as {
    status?: number;
    response?: { status?: number };
    code?: string | number;
  };
  return e.status === 409 || e.response?.status === 409 || e.code === 409;
}
