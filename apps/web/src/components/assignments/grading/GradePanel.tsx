'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui';
import {
  useGradeSubmission,
  useInstructorSubmission,
  useReleaseGrade,
  useRequestChanges,
} from '@/lib/assignments/assignments-hooks';
import { useAssignmentsI18n } from '@/lib/assignments/assignments-i18n';
import { FeedbackEditor } from './FeedbackEditor';
import { LearnerSubmissionViewer } from './LearnerSubmissionViewer';
import { RubricGrader } from './RubricGrader';
import type { FileUrlResolver } from './SubmissionFileList';
import type { InstructorSubmission } from './types';
import {
  computeRubricScore,
  isConflictError,
  selectionFromResult,
  selectionToResult,
  type RubricSelection,
} from './utils';

interface GradePanelProps {
  submissionId: string;
  maxGrade?: number | null;
  passingGrade?: number | null;
  resolveFileUrl?: FileUrlResolver;
  onGraded?: () => void;
}

/**
 * Full grading surface for one submission: learner work, rubric grading (or a numeric score),
 * feedback, private notes, save-draft-grade, request-changes and release. Optimistic concurrency is
 * enforced via `expected_version`; a 409 reloads the server copy and warns the grader instead of
 * silently overwriting a concurrent change.
 */
export function GradePanel({
  submissionId,
  maxGrade,
  passingGrade,
  resolveFileUrl,
  onGraded,
}: GradePanelProps) {
  const { t } = useAssignmentsI18n();
  const query = useInstructorSubmission(submissionId);
  const grade = useGradeSubmission(submissionId);
  const requestChanges = useRequestChanges(submissionId);
  const release = useReleaseGrade(submissionId);

  const submission = query.data as InstructorSubmission | undefined;
  const rubric = submission?.rubric_snapshot ?? null;
  const usesRubric = Boolean(rubric && rubric.criteria.length > 0);

  const [selection, setSelection] = useState<RubricSelection>({});
  const [score, setScore] = useState('');
  const [feedback, setFeedback] = useState('');
  const [privateNotes, setPrivateNotes] = useState('');
  const [expectedVersion, setExpectedVersion] = useState(0);
  const [conflict, setConflict] = useState(false);
  const [noteOpen, setNoteOpen] = useState(false);
  const [note, setNote] = useState('');

  // Hydrate local draft from server on load and whenever the server version changes (incl. after a
  // conflict reload). Guarded so ordinary re-renders don't clobber in-progress edits.
  const syncedRef = useRef<{ id: string; version: number } | null>(null);
  useEffect(() => {
    if (!submission) return;
    const version = submission.grade?.version ?? 0;
    const prev = syncedRef.current;
    if (prev && prev.id === submission.id && prev.version === version) return;
    syncedRef.current = { id: submission.id, version };
    setSelection(selectionFromResult(submission.grade?.rubric_result));
    setScore(submission.grade?.score != null ? String(submission.grade.score) : '');
    setFeedback(submission.grade?.feedback ?? '');
    setPrivateNotes(submission.grade?.private_notes ?? '');
    setExpectedVersion(version);
  }, [submission]);

  const handleSelect = useCallback((criterionId: string, levelId: string) => {
    setSelection((s) => ({ ...s, [criterionId]: levelId }));
  }, []);

  const runMutation = useCallback(
    async (fn: () => Promise<unknown>) => {
      setConflict(false);
      try {
        await fn();
        const refreshed = await query.refetch();
        // Re-hydrate from the authoritative server copy (bumps expected_version).
        const next = (refreshed.data as InstructorSubmission | undefined) ?? undefined;
        if (next) {
          syncedRef.current = null; // force the hydrate effect to re-run
        }
        onGraded?.();
      } catch (err) {
        if (isConflictError(err)) {
          setConflict(true);
          syncedRef.current = null;
          await query.refetch(); // reload latest; hydrate effect resets the draft to server truth
        } else {
          throw err;
        }
      }
    },
    [onGraded, query],
  );

  const saveGrade = useCallback(() => {
    const payload: Record<string, unknown> = {
      feedback: feedback || null,
      private_notes: privateNotes || null,
      expected_version: expectedVersion,
    };
    if (usesRubric) {
      payload.rubric_result = selectionToResult(selection);
    } else {
      payload.score = score === '' ? null : Number(score);
    }
    return runMutation(() => grade.mutateAsync(payload));
  }, [expectedVersion, feedback, grade, privateNotes, runMutation, score, selection, usesRubric]);

  const doRelease = useCallback(
    () => runMutation(() => release.mutateAsync(undefined)),
    [release, runMutation],
  );

  const doRequestChanges = useCallback(async () => {
    await runMutation(() => requestChanges.mutateAsync({ note: note || null }));
    setNoteOpen(false);
    setNote('');
  }, [note, requestChanges, runMutation]);

  if (query.isLoading) {
    return (
      <p data-testid="grade-loading" className="text-sm text-slate-500">
        {t('common.loading', 'Loading…')}
      </p>
    );
  }
  if (query.isError || !submission) {
    return (
      <p role="alert" data-testid="grade-load-error" className="text-sm text-red-600">
        {t('assignments.grading.loadError', 'Could not load this submission.')}
      </p>
    );
  }

  const breakdown = computeRubricScore(rubric, selection, maxGrade);
  const released = submission.grade?.released_at != null;
  const busy = grade.isPending || release.isPending || requestChanges.isPending || query.isFetching;

  return (
    <div data-testid="grade-panel" className="grid gap-6 lg:grid-cols-2">
      <LearnerSubmissionViewer submission={submission} resolveFileUrl={resolveFileUrl} />

      <div className="space-y-5">
        {conflict && (
          <div
            role="alert"
            data-testid="conflict-warning"
            className="space-y-1 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800"
          >
            <p className="font-semibold">
              {t('assignments.grading.conflict.title', 'This submission changed elsewhere')}
            </p>
            <p>
              {t(
                'assignments.grading.conflict.body',
                'Another change was saved while you were grading. We reloaded the latest version — review it and re-apply your grade.',
              )}
            </p>
          </div>
        )}

        {released && (
          <div
            data-testid="already-released"
            className="rounded-md border border-green-300 bg-green-50 p-2 text-xs font-medium text-green-800"
          >
            {t('assignments.grading.released', 'This grade has been released to the learner.')}
          </div>
        )}

        {usesRubric && rubric ? (
          <RubricGrader
            rubric={rubric}
            selection={selection}
            onSelect={handleSelect}
            maxGrade={maxGrade}
            disabled={busy}
          />
        ) : (
          <label className="block">
            <span className="mb-1 block text-sm font-semibold text-slate-800">
              {t('assignments.grading.score.label', 'Score')}
            </span>
            <span className="flex items-center gap-2">
              <input
                type="number"
                data-testid="score-input"
                value={score}
                min={0}
                max={maxGrade ?? undefined}
                disabled={busy}
                onChange={(e) => setScore(e.target.value)}
                className="w-28 rounded-md border border-slate-300 p-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              {maxGrade != null && <span className="text-sm text-slate-500">/ {maxGrade}</span>}
            </span>
          </label>
        )}

        {usesRubric && (
          <p className="text-xs text-slate-500" data-testid="computed-score">
            {t(
              'assignments.grading.computedScore',
              `Computed score: ${maxGrade != null ? breakdown.scaled : breakdown.raw} / ${maxGrade ?? breakdown.outOf}`,
            )}
            {passingGrade != null &&
              ` · ${t('assignments.grading.passMark', `pass ≥ ${passingGrade}`)}`}
          </p>
        )}

        <FeedbackEditor
          feedback={feedback}
          privateNotes={privateNotes}
          onFeedbackChange={setFeedback}
          onPrivateNotesChange={setPrivateNotes}
          disabled={busy}
        />

        {grade.isError && !conflict && (
          <p role="alert" data-testid="save-error" className="text-sm text-red-600">
            {t('assignments.grading.saveError', 'Could not save the grade.')}
          </p>
        )}

        <div className="flex flex-wrap items-center gap-2">
          <Button
            type="button"
            variant="secondary"
            data-testid="save-draft-grade"
            disabled={busy}
            onClick={() => void saveGrade()}
          >
            {grade.isPending
              ? t('assignments.grading.saving', 'Saving…')
              : t('assignments.grading.saveDraft', 'Save draft grade')}
          </Button>

          <Button
            type="button"
            variant="ghost"
            data-testid="request-changes-open"
            disabled={busy}
            onClick={() => setNoteOpen((v) => !v)}
          >
            {t('assignments.grading.requestChanges', 'Request changes')}
          </Button>

          <Button
            type="button"
            variant="primary"
            data-testid="release-grade"
            disabled={busy || released}
            onClick={() => void doRelease()}
          >
            {release.isPending
              ? t('assignments.grading.releasing', 'Releasing…')
              : t('assignments.grading.release', 'Release grade')}
          </Button>
        </div>

        {noteOpen && (
          <div data-testid="request-changes-form" className="space-y-2 rounded-md border border-slate-200 p-3">
            <label className="block text-sm font-medium text-slate-700">
              {t('assignments.grading.requestChanges.noteLabel', 'Note to the learner (optional)')}
            </label>
            <textarea
              data-testid="request-changes-note"
              value={note}
              rows={3}
              maxLength={20000}
              onChange={(e) => setNote(e.target.value)}
              className="w-full rounded-md border border-slate-300 p-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
            <div className="flex justify-end gap-2">
              <Button
                type="button"
                variant="ghost"
                disabled={busy}
                onClick={() => setNoteOpen(false)}
              >
                {t('common.cancel', 'Cancel')}
              </Button>
              <Button
                type="button"
                variant="primary"
                data-testid="request-changes-confirm"
                disabled={busy}
                onClick={() => void doRequestChanges()}
              >
                {t('assignments.grading.requestChanges.send', 'Send back for changes')}
              </Button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
