'use client';

import { useMemo } from 'react';
import {
  useAttachDraftFile,
  useDetachDraftFile,
  useLearnerAssignment,
  useSubmissionHistory,
} from '@/lib/assignments/assignments-hooks';
import { useAssignmentsI18n } from '@/lib/assignments/assignments-i18n';
import type { LearnerAssignment, LearnerSubmission, SubmissionFile } from './types';
import { AttemptCounter } from './AttemptCounter';
import { ChangesRequestedBanner } from './ChangesRequestedBanner';
import { DraftEditor } from './DraftEditor';
import { LateWarning } from './LateWarning';
import { ReleasedGradeView } from './ReleasedGradeView';
import { SubmissionFileUploader } from './SubmissionFileUploader';
import { SubmissionHistory } from './SubmissionHistory';
import { SubmitConfirmation } from './SubmitConfirmation';
import type { SubmissionUploadClient, UploadTransport } from './upload/uploadClient';

interface AssignmentSubmissionPanelProps {
  assignmentId: string;
  courseId?: string | null;
  /** Injectable upload pipeline for tests. */
  uploadClient?: SubmissionUploadClient;
  uploadTransport?: UploadTransport;
  now?: number;
}

const EDITABLE_STATUSES = new Set(['draft', 'changes_requested']);

function pickActive(submissions: LearnerSubmission[]): LearnerSubmission | undefined {
  const editable = submissions.filter((s) => EDITABLE_STATUSES.has(s.status));
  if (editable.length > 0) {
    return editable.reduce((a, b) => (b.attempt_no > a.attempt_no ? b : a));
  }
  return submissions.reduce<LearnerSubmission | undefined>(
    (a, b) => (a && a.attempt_no > b.attempt_no ? a : b),
    undefined,
  );
}

/**
 * Top-level learner surface: assignment brief, draft editor, file attachments, submit/resubmit
 * confirmation, attempt counter, late warning, submission history and the released grade. Composes
 * D3's assignments hooks; owns no data layer of its own.
 */
export function AssignmentSubmissionPanel({
  assignmentId,
  courseId,
  uploadClient,
  uploadTransport,
  now,
}: AssignmentSubmissionPanelProps) {
  const { t } = useAssignmentsI18n();
  const assignmentQuery = useLearnerAssignment(assignmentId);
  const historyQuery = useSubmissionHistory(assignmentId);
  const attach = useAttachDraftFile(assignmentId);
  const detach = useDetachDraftFile();

  const assignment = assignmentQuery.data as LearnerAssignment | undefined;
  const submissions = (historyQuery.data as LearnerSubmission[] | undefined) ?? [];

  const active = useMemo(() => pickActive(submissions), [submissions]);
  const attemptsUsed = submissions.filter((s) => s.status !== 'draft').length;
  const editable = active ? EDITABLE_STATUSES.has(active.status) : true;
  const mode: 'submit' | 'resubmit' = active?.status === 'changes_requested' ? 'resubmit' : 'submit';

  const releasedSubmission = useMemo(
    () =>
      [...submissions]
        .sort((a, b) => b.attempt_no - a.attempt_no)
        .find((s) => s.grade != null),
    [submissions],
  );

  if (assignmentQuery.isLoading) {
    return (
      <p data-testid="assignment-loading" className="text-sm text-muted-foreground">
        {t('common.loading', 'Loading…')}
      </p>
    );
  }
  if (assignmentQuery.isError || !assignment) {
    return (
      <p role="alert" data-testid="assignment-error" className="text-sm text-destructive">
        {t('assignments.submission.loadError', 'Could not load this assignment.')}
      </p>
    );
  }

  const attachedFiles: SubmissionFile[] = active?.files ?? [];
  const hasContent =
    (active?.text_response?.trim().length ?? 0) > 0 ||
    (active?.external_url?.trim().length ?? 0) > 0 ||
    attachedFiles.length > 0;

  return (
    <div data-testid="assignment-submission-panel" className="space-y-6">
      <header className="space-y-2">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-lg font-semibold text-foreground">{assignment.title}</h1>
          <AttemptCounter attemptLimit={assignment.attempt_limit} attemptsUsed={attemptsUsed} />
          {active && <span className="text-xs text-muted-foreground">·</span>}
        </div>
        {assignment.instructions && (
          <p className="whitespace-pre-wrap text-sm text-muted-foreground">{assignment.instructions}</p>
        )}
        {assignment.due_at && (
          <p className="text-xs text-muted-foreground">
            {t('assignments.submission.due', `Due ${new Date(assignment.due_at).toLocaleString()}`)}
          </p>
        )}
      </header>

      {active?.status === 'changes_requested' && (
        <ChangesRequestedBanner note={active.grade?.feedback ?? null} />
      )}

      <LateWarning
        dueAt={assignment.due_at}
        latePolicy={assignment.late_policy}
        now={now}
      />

      {editable ? (
        <div className="space-y-5">
          <DraftEditor
            assignmentId={assignmentId}
            submissionType={assignment.submission_type}
            initialText={active?.text_response}
            initialUrl={active?.external_url}
          />
          {assignment.submission_type !== 'text' && assignment.submission_type !== 'url' && (
            <SubmissionFileUploader
              assignment={assignment}
              attachedFiles={attachedFiles}
              courseId={courseId}
              client={uploadClient}
              transport={uploadTransport}
              onAttach={(mediaId) => attach.mutateAsync({ media_id: mediaId }).then(() => undefined)}
              onDetach={(file) =>
                detach
                  .mutateAsync({ submissionId: active?.id ?? '', fileId: file.id })
                  .then(() => undefined)
              }
            />
          )}
          <div className="flex items-center gap-3">
            <SubmitConfirmation
              assignmentId={assignmentId}
              mode={mode}
              dueAt={assignment.due_at}
              latePolicy={assignment.late_policy}
              attemptLimit={assignment.attempt_limit}
              attemptsUsed={attemptsUsed}
              disabled={!hasContent}
              now={now}
            />
            {!hasContent && (
              <span className="text-xs text-muted-foreground">
                {t('assignments.submission.needContent', 'Add a response or a file before submitting.')}
              </span>
            )}
          </div>
        </div>
      ) : (
        <p data-testid="submission-locked" className="text-sm text-muted-foreground">
          {t('assignments.submission.locked', 'Your submission is being reviewed.')}
        </p>
      )}

      {releasedSubmission && (
        <ReleasedGradeView
          submission={releasedSubmission}
          maxGrade={assignment.max_grade}
          passingGrade={assignment.passing_grade}
        />
      )}

      <SubmissionHistory
        submissions={submissions}
        maxGrade={assignment.max_grade}
        activeSubmissionId={active?.id}
      />
    </div>
  );
}
