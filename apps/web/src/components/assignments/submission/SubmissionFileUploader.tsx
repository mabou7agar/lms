'use client';

import { useRef } from 'react';
import { Button } from '@/components/ui';
import { useAssignmentsI18n } from '@/lib/assignments/assignments-i18n';
import type { LearnerAssignment, SubmissionFile } from './types';
import { fileKey, formatBytes } from './utils';
import {
  useSubmissionFileUpload,
  type UploadItem,
} from './upload/useSubmissionFileUpload';
import type { SubmissionUploadClient, UploadTransport } from './upload/uploadClient';

interface SubmissionFileUploaderProps {
  assignment: LearnerAssignment;
  attachedFiles: SubmissionFile[];
  courseId?: string | null;
  disabled?: boolean;
  /** Attach a finalized media id to the draft (D3 mutation). */
  onAttach: (mediaId: string) => Promise<void>;
  /** Remove an already-attached file from the draft (D3 mutation). */
  onDetach: (file: SubmissionFile) => Promise<void>;
  /** Injectable pipeline for tests. */
  client?: SubmissionUploadClient;
  transport?: UploadTransport;
  mediaType?: string;
  mediaPurpose?: string;
}

const STAGE_LABEL: Record<UploadItem['stage'], string> = {
  validating: 'Preparing…',
  requesting: 'Preparing upload…',
  uploading: 'Uploading…',
  finalizing: 'Finalizing…',
  attaching: 'Attaching…',
  done: 'Attached',
  error: 'Failed',
};

/**
 * Learner file attachment control. Validates each file, uploads bytes directly to the storage
 * provider with a live progress bar, then attaches the resulting media id to the draft. Enforces
 * `max_files`, `max_file_size` and `allowed_file_types` in the browser before any upload starts.
 */
export function SubmissionFileUploader({
  assignment,
  attachedFiles,
  courseId,
  disabled,
  onAttach,
  onDetach,
  client,
  transport,
  mediaType,
  mediaPurpose,
}: SubmissionFileUploaderProps) {
  const { t } = useAssignmentsI18n();
  const inputRef = useRef<HTMLInputElement>(null);

  const { items, addFiles, retry, dismiss, isUploading } = useSubmissionFileUpload({
    assignment,
    attachedCount: attachedFiles.length,
    courseId,
    attach: onAttach,
    client,
    transport,
    mediaType,
    mediaPurpose,
  });

  const max = assignment.max_files ?? Infinity;
  const inFlight = items.filter((i) => i.stage !== 'error').length;
  const atLimit = attachedFiles.length + inFlight >= max;
  const accept = (assignment.allowed_file_types ?? [])
    .map((x) => `.${x.replace(/^\./, '')}`)
    .join(',');

  return (
    <section data-testid="file-uploader" className="space-y-3">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-foreground">
          {t('assignments.submission.files.title', 'Files')}
        </h3>
        <span className="text-xs text-muted-foreground">
          {(assignment.allowed_file_types ?? []).length > 0
            ? t(
                'assignments.submission.files.allowed',
                `Allowed: ${(assignment.allowed_file_types ?? []).join(', ')}`,
              )
            : t('assignments.submission.files.anyType', 'Any file type')}
          {assignment.max_file_size ? ` · ≤ ${formatBytes(assignment.max_file_size)}` : ''}
          {assignment.max_files ? ` · up to ${assignment.max_files}` : ''}
        </span>
      </div>

      {/* Already-attached draft files */}
      {attachedFiles.length > 0 && (
        <ul data-testid="attached-files" className="divide-y rounded-md border border-border">
          {attachedFiles.map((f) => (
            <li key={fileKey(f)} className="flex items-center justify-between px-3 py-2 text-sm">
              <span className="truncate text-foreground">{f.filename}</span>
              <button
                type="button"
                disabled={disabled}
                onClick={() => void onDetach(f)}
                className="text-xs font-medium text-destructive hover:underline disabled:opacity-50"
                aria-label={t('assignments.submission.files.remove', 'Remove file')}
              >
                {t('assignments.submission.files.remove', 'Remove')}
              </button>
            </li>
          ))}
        </ul>
      )}

      {/* In-flight / failed uploads */}
      {items.length > 0 && (
        <ul data-testid="upload-items" className="space-y-2">
          {items.map((it) => (
            <li
              key={it.localId}
              data-testid={`upload-item-${it.localId}`}
              data-stage={it.stage}
              className="rounded-md border border-border px-3 py-2 text-sm"
            >
              <div className="flex items-center justify-between">
                <span className="truncate text-foreground">{it.name}</span>
                <span className="ms-2 shrink-0 text-xs text-muted-foreground">
                  {STAGE_LABEL[it.stage]}
                </span>
              </div>
              {it.stage !== 'error' && it.stage !== 'done' && (
                <div
                  role="progressbar"
                  aria-valuemin={0}
                  aria-valuemax={100}
                  aria-valuenow={Math.round(it.progress * 100)}
                  data-testid={`upload-progress-${it.localId}`}
                  className="mt-1 h-1.5 w-full overflow-hidden rounded bg-muted"
                >
                  <div
                    className="h-full bg-primary transition-[width]"
                    style={{ width: `${Math.round(it.progress * 100)}%` }}
                  />
                </div>
              )}
              {it.stage === 'error' && (
                <div className="mt-1 flex items-center justify-between gap-2">
                  <span role="alert" className="text-xs text-destructive">
                    {it.error}
                  </span>
                  <span className="flex gap-2">
                    <button
                      type="button"
                      onClick={() => retry(it.localId)}
                      className="text-xs font-medium text-primary hover:underline"
                    >
                      {t('assignments.submission.files.retry', 'Retry')}
                    </button>
                    <button
                      type="button"
                      onClick={() => dismiss(it.localId)}
                      className="text-xs font-medium text-muted-foreground hover:underline"
                    >
                      {t('assignments.submission.files.dismiss', 'Dismiss')}
                    </button>
                  </span>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      <div>
        <input
          ref={inputRef}
          type="file"
          multiple={max > 1}
          accept={accept || undefined}
          data-testid="file-input"
          className="sr-only"
          disabled={disabled || atLimit}
          onChange={(e) => {
            if (e.target.files) addFiles(e.target.files);
            e.target.value = '';
          }}
        />
        <Button
          type="button"
          variant="secondary"
          disabled={disabled || atLimit}
          onClick={() => inputRef.current?.click()}
        >
          {t('assignments.submission.files.add', 'Add file')}
        </Button>
        {atLimit && (
          <p className="mt-1 text-xs text-muted-foreground">
            {t('assignments.submission.files.limitReached', 'Maximum number of files reached.')}
          </p>
        )}
      </div>

      {/* Signal to the parent that a submit should wait for uploads to settle. */}
      <input type="hidden" data-testid="uploading-flag" value={isUploading ? '1' : '0'} readOnly />
    </section>
  );
}
