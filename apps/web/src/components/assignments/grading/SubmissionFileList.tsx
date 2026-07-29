'use client';

import { useState } from 'react';
import { apiClient } from '@/lib/api/client';
import { useAssignmentsI18n } from '@/lib/assignments/assignments-i18n';
import type { GradingSubmissionFile } from './types';

/** Resolve a short-lived signed URL for a submission file's media asset. Injectable for tests. */
export type FileUrlResolver = (mediaId: string) => Promise<string>;

/**
 * Default resolver: asks Media for a signed URL by the file's media PUBLIC id. The signed URL is
 * fetched lazily (only when the grader opens a file) so raw asset URLs are never embedded in the
 * page. Endpoint shape is a CONTRACT ASSUMPTION — see deliverable notes.
 */
export const defaultFileUrlResolver: FileUrlResolver = async (mediaId) => {
  const { data } = await apiClient.get<{ url?: string; data?: { url: string } }>(
    `/v1/media/assets/${mediaId}/signed-url`,
  );
  const url = data.url ?? data.data?.url;
  if (!url) throw new Error('No signed URL returned');
  return url;
};

interface SubmissionFileListProps {
  files: GradingSubmissionFile[];
  resolveUrl?: FileUrlResolver;
}

type RowState = 'idle' | 'loading' | 'error';

/**
 * Secure file access for graders. Each file resolves a signed URL on demand and opens it in a new
 * tab; nothing is pre-fetched. Denied/expired access surfaces an inline error rather than a broken
 * link.
 */
export function SubmissionFileList({ files, resolveUrl = defaultFileUrlResolver }: SubmissionFileListProps) {
  const { t } = useAssignmentsI18n();
  const [states, setStates] = useState<Record<string, RowState>>({});

  const open = async (file: GradingSubmissionFile) => {
    setStates((s) => ({ ...s, [file.id]: 'loading' }));
    try {
      const url = await resolveUrl(file.media_id);
      setStates((s) => ({ ...s, [file.id]: 'idle' }));
      if (typeof window !== 'undefined') window.open(url, '_blank', 'noopener,noreferrer');
    } catch {
      setStates((s) => ({ ...s, [file.id]: 'error' }));
    }
  };

  if (files.length === 0) {
    return (
      <p data-testid="grader-files-empty" className="text-sm text-slate-500">
        {t('assignments.grading.files.empty', 'No files attached.')}
      </p>
    );
  }

  return (
    <ul data-testid="grader-files" className="divide-y rounded-md border border-slate-200">
      {files.map((file) => {
        const state = states[file.id] ?? 'idle';
        return (
          <li key={file.id} className="flex items-center justify-between px-3 py-2 text-sm">
            <span className="truncate text-slate-700">{file.filename}</span>
            <span className="flex items-center gap-2">
              {state === 'error' && (
                <span role="alert" className="text-xs text-red-600" data-testid={`file-error-${file.id}`}>
                  {t('assignments.grading.files.error', 'Access denied')}
                </span>
              )}
              <button
                type="button"
                data-testid={`file-open-${file.id}`}
                disabled={state === 'loading'}
                onClick={() => void open(file)}
                className="text-xs font-medium text-blue-600 hover:underline disabled:opacity-50"
              >
                {state === 'loading'
                  ? t('assignments.grading.files.opening', 'Opening…')
                  : t('assignments.grading.files.open', 'View file')}
              </button>
            </span>
          </li>
        );
      })}
    </ul>
  );
}
