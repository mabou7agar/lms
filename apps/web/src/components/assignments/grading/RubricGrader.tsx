'use client';

import { useAssignmentsI18n } from '@/lib/assignments/assignments-i18n';
import type { Rubric } from './types';
import { computeRubricScore, type RubricSelection } from './utils';

interface RubricGraderProps {
  rubric: Rubric;
  selection: RubricSelection;
  onSelect: (criterionId: string, levelId: string) => void;
  maxGrade?: number | null;
  disabled?: boolean;
}

/**
 * Rubric grading grid. The grader selects one level per criterion; the score is computed live from
 * the (immutable) rubric snapshot and shown both raw and scaled to the assignment max grade. The
 * selection is the source of truth sent as `rubric_result` — the backend recomputes authoritatively.
 */
export function RubricGrader({ rubric, selection, onSelect, maxGrade, disabled }: RubricGraderProps) {
  const { t } = useAssignmentsI18n();
  const breakdown = computeRubricScore(rubric, selection, maxGrade);

  return (
    <section data-testid="rubric-grader" className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-foreground">
          {rubric.title || t('assignments.grading.rubric.title', 'Rubric')}
        </h3>
        <div
          data-testid="rubric-score"
          data-raw={breakdown.raw}
          data-scaled={breakdown.scaled}
          data-complete={breakdown.complete}
          className="text-right"
        >
          <span className="text-lg font-bold text-foreground">
            {maxGrade != null ? breakdown.scaled : breakdown.raw}
          </span>
          <span className="text-xs text-muted-foreground">
            {' '}
            / {maxGrade ?? breakdown.outOf}
          </span>
          <div className="text-[11px] text-muted-foreground">
            {t(
              'assignments.grading.rubric.progress',
              `${breakdown.selectedCount}/${breakdown.criterionCount} scored`,
            )}
          </div>
        </div>
      </div>

      <div className="space-y-3">
        {rubric.criteria.map((criterion) => {
          const chosen = selection[criterion.id];
          return (
            <fieldset
              key={criterion.id}
              data-testid={`criterion-${criterion.id}`}
              className="rounded-md border border-border p-3"
            >
              <legend className="flex items-center gap-2 px-1 text-sm font-medium text-foreground">
                {criterion.title}
                <span className="text-xs font-normal text-muted-foreground">
                  ({t('assignments.grading.rubric.maxPoints', `max ${criterion.max_points}`)})
                </span>
              </legend>
              {criterion.description && (
                <p className="mb-2 text-xs text-muted-foreground">{criterion.description}</p>
              )}
              <div className="grid gap-2 sm:grid-cols-2">
                {criterion.levels.map((level) => {
                  const active = chosen === level.id;
                  return (
                    <label
                      key={level.id}
                      data-testid={`level-${level.id}`}
                      data-selected={active}
                      className={`flex cursor-pointer items-start gap-2 rounded-md border p-2 text-sm ${active ? 'border-primary bg-primary/[0.06]' : 'border-border hover:border-border'} ${disabled ? 'cursor-not-allowed opacity-60' : ''}`}
                    >
                      <input
                        type="radio"
                        name={`criterion-${criterion.id}`}
                        value={level.id}
                        checked={active}
                        disabled={disabled}
                        onChange={() => onSelect(criterion.id, level.id)}
                        className="mt-0.5"
                      />
                      <span>
                        <span className="flex items-center gap-1 font-medium text-foreground">
                          {level.title}
                          <span className="text-xs font-semibold text-muted-foreground">
                            {level.points}
                          </span>
                        </span>
                        {level.description && (
                          <span className="block text-xs text-muted-foreground">{level.description}</span>
                        )}
                      </span>
                    </label>
                  );
                })}
              </div>
            </fieldset>
          );
        })}
      </div>
    </section>
  );
}
