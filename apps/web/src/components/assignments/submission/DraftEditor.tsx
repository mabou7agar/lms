'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui';
import { useSaveDraft } from '@/lib/assignments/assignments-hooks';
import { useAssignmentsI18n } from '@/lib/assignments/assignments-i18n';
import type { SubmissionType } from './types';

interface DraftEditorProps {
  assignmentId: string;
  submissionType: SubmissionType;
  initialText?: string | null;
  initialUrl?: string | null;
  disabled?: boolean;
  /** Debounce for autosave in ms; set 0 to disable autosave (manual save only). */
  autosaveMs?: number;
}

function showsText(type: SubmissionType): boolean {
  return type !== 'file' && type !== 'url';
}
function showsUrl(type: SubmissionType): boolean {
  return typeof type === 'string' && type.includes('url');
}

/**
 * Draft text + external URL editor. Persists via D3's `useSaveDraft`. Debounced autosave keeps the
 * server copy fresh; an explicit "Save draft" button gives the learner a manual save + status.
 */
export function DraftEditor({
  assignmentId,
  submissionType,
  initialText,
  initialUrl,
  disabled,
  autosaveMs = 1200,
}: DraftEditorProps) {
  const { t } = useAssignmentsI18n();
  const save = useSaveDraft(assignmentId);

  const [text, setText] = useState(initialText ?? '');
  const [url, setUrl] = useState(initialUrl ?? '');
  const [savedAt, setSavedAt] = useState<number | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const dirty = useRef(false);

  const persist = useCallback(async () => {
    if (!dirty.current) return;
    dirty.current = false;
    await save.mutateAsync({
      text_response: showsText(submissionType) ? text : undefined,
      external_url: showsUrl(submissionType) ? url || null : undefined,
    });
    setSavedAt(Date.now());
  }, [save, submissionType, text, url]);

  // Debounced autosave
  useEffect(() => {
    if (autosaveMs <= 0 || !dirty.current) return;
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => {
      void persist();
    }, autosaveMs);
    return () => {
      if (timer.current) clearTimeout(timer.current);
    };
  }, [text, url, autosaveMs, persist]);

  const markDirty = () => {
    dirty.current = true;
    setSavedAt(null);
  };

  return (
    <section data-testid="draft-editor" className="space-y-3">
      {showsText(submissionType) && (
        <label className="block">
          <span className="mb-1 block text-sm font-semibold text-foreground">
            {t('assignments.submission.draft.responseLabel', 'Your response')}
          </span>
          <textarea
            data-testid="draft-text"
            value={text}
            disabled={disabled}
            rows={8}
            onChange={(e) => {
              setText(e.target.value);
              markDirty();
            }}
            className="w-full rounded-md border border-border p-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-ring disabled:bg-surface/40"
            placeholder={t('assignments.submission.draft.responsePlaceholder', 'Write your response…')}
          />
        </label>
      )}

      {showsUrl(submissionType) && (
        <label className="block">
          <span className="mb-1 block text-sm font-semibold text-foreground">
            {t('assignments.submission.draft.urlLabel', 'Submission URL')}
          </span>
          <input
            type="url"
            data-testid="draft-url"
            value={url}
            disabled={disabled}
            onChange={(e) => {
              setUrl(e.target.value);
              markDirty();
            }}
            className="w-full rounded-md border border-border p-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-ring disabled:bg-surface/40"
            placeholder="https://…"
          />
        </label>
      )}

      <div className="flex items-center gap-3">
        <Button
          type="button"
          variant="secondary"
          disabled={disabled || save.isPending}
          onClick={() => {
            dirty.current = true;
            void persist();
          }}
        >
          {save.isPending
            ? t('assignments.submission.draft.saving', 'Saving…')
            : t('assignments.submission.draft.save', 'Save draft')}
        </Button>
        {save.isError && (
          <span role="alert" className="text-xs text-destructive" data-testid="draft-save-error">
            {t('assignments.submission.draft.saveError', 'Could not save draft.')}
          </span>
        )}
        {savedAt && !save.isPending && !save.isError && (
          <span className="text-xs text-primary" data-testid="draft-saved">
            {t('assignments.submission.draft.saved', 'Draft saved')}
          </span>
        )}
      </div>
    </section>
  );
}
