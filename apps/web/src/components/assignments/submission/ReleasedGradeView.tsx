'use client';

import { useAssignmentsI18n } from '@/lib/assignments/assignments-i18n';
import type { LearnerSubmission, RubricResultEntry, Rubric } from './types';

interface ReleasedGradeViewProps {
  submission: LearnerSubmission;
  maxGrade?: number;
  passingGrade?: number | null;
}

interface ResolvedRow {
  criterionTitle: string;
  levelTitle: string | null;
  points: number | null;
  maxPoints: number;
  comment?: string | null;
}

function resolveRubricResult(
  rubric: Rubric | null,
  result: RubricResultEntry[] | null | undefined,
): ResolvedRow[] {
  if (!rubric || !result) return [];
  return result.map((entry) => {
    const criterion = rubric.criteria.find((c) => c.id === entry.criterion_public_id);
    const level = criterion?.levels.find((l) => l.id === entry.level_public_id);
    return {
      criterionTitle: criterion?.title ?? entry.criterion_public_id,
      levelTitle: level?.title ?? null,
      points: level?.points ?? null,
      maxPoints: criterion?.max_points ?? 0,
      comment: entry.comment ?? null,
    };
  });
}

/**
 * Learner-facing released grade: score, pass/fail, written feedback and the rubric result mapped
 * against the immutable rubric snapshot. NEVER renders private instructor notes — the learner grade
 * shape carries none, and this component reads no such field.
 */
export function ReleasedGradeView({ submission, maxGrade, passingGrade }: ReleasedGradeViewProps) {
  const { t } = useAssignmentsI18n();
  const grade = submission.grade;
  if (!grade) {
    return (
      <p data-testid="grade-pending" className="text-sm text-muted-foreground">
        {t('assignments.submission.grade.pending', 'Your grade has not been released yet.')}
      </p>
    );
  }

  const rows = resolveRubricResult(submission.rubric_snapshot, grade.rubric_result);
  const passed = grade.passed;

  return (
    <section data-testid="released-grade" className="space-y-4 rounded-2xl border border-border/70 bg-card p-5">
      <div className="flex items-center justify-between">
        <h3 className="font-serif text-lg font-semibold">
          {t('assignments.submission.grade.title', 'Your grade')}
        </h3>
        {passed != null && (
          <span
            data-testid="grade-passed"
            data-passed={passed}
            className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ${passed ? 'bg-primary/10 text-primary' : 'bg-destructive/10 text-destructive'}`}
          >
            {passed
              ? t('assignments.submission.grade.passed', 'Passed')
              : t('assignments.submission.grade.notPassed', 'Not passed')}
          </span>
        )}
      </div>

      {grade.score != null && (
        <p data-testid="grade-score" className="font-serif text-4xl font-bold tabular-nums">
          {grade.score}
          {maxGrade != null && <span className="text-lg font-normal text-muted-foreground"> / {maxGrade}</span>}
          {passingGrade != null && (
            <span className="ms-2 text-xs font-normal text-muted-foreground">
              {t('assignments.submission.grade.passMark', `Pass mark ${passingGrade}`)}
            </span>
          )}
        </p>
      )}

      {rows.length > 0 && (
        <div data-testid="grade-rubric" className="space-y-2">
          {rows.map((row, i) => (
            <div key={i} className="rounded-xl border border-border/60 bg-surface/40 p-3 text-sm">
              <div className="flex items-center justify-between gap-3">
                <span className="font-medium">{row.criterionTitle}</span>
                <span className="text-muted-foreground">
                  {row.levelTitle ?? '—'}
                  {row.points != null && (
                    <span className="ms-1 font-semibold text-copper">
                      ({row.points}/{row.maxPoints})
                    </span>
                  )}
                </span>
              </div>
              {row.comment && <p className="mt-1 text-xs text-muted-foreground">{row.comment}</p>}
            </div>
          ))}
        </div>
      )}

      {grade.feedback && (
        <div data-testid="grade-feedback" className="rounded-xl border border-border/60 bg-surface/40 p-3">
          <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-copper">
            {t('assignments.submission.grade.feedback', 'Feedback')}
          </h4>
          <p className="whitespace-pre-wrap text-sm text-foreground">{grade.feedback}</p>
        </div>
      )}
    </section>
  );
}
