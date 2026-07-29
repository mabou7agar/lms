import { useCallback, useRef, useState } from 'react';
import type { LearnerAssignment } from '../types';
import { validateFile } from '../utils';
import {
  createDefaultUploadClient,
  xhrUploadTransport,
  type SubmissionUploadClient,
  type UploadTransport,
} from './uploadClient';

export type UploadStage =
  | 'validating'
  | 'requesting'
  | 'uploading'
  | 'finalizing'
  | 'attaching'
  | 'done'
  | 'error';

export interface UploadItem {
  localId: string;
  name: string;
  size: number;
  stage: UploadStage;
  progress: number; // 0..1
  error?: string;
  mediaId?: string;
}

export interface UseSubmissionFileUploadOptions {
  assignment: Pick<
    LearnerAssignment,
    'allowed_file_types' | 'max_file_size' | 'max_files'
  >;
  /** Number of files already attached to the draft on the server. */
  attachedCount: number;
  courseId?: string | null;
  /** MediaType value; defaults to 'document'. See CONTRACT ASSUMPTIONS. */
  mediaType?: string;
  /** MediaPurpose value for submission attachments. See CONTRACT ASSUMPTIONS. */
  mediaPurpose?: string;
  /** Attach the finalized media public id to the draft (D3 assignments mutation). */
  attach: (mediaId: string) => Promise<void>;
  /** Injectable for tests. */
  client?: SubmissionUploadClient;
  transport?: UploadTransport;
}

let seq = 0;
function nextLocalId(): string {
  seq += 1;
  return `u${seq}-${Date.now()}`;
}

function idempotencyKey(file: File): string {
  return `sub-${file.name}-${file.size}-${file.lastModified}`;
}

/**
 * Orchestrates the learner file pipeline: validate -> request ticket -> direct upload (progress)
 * -> finalize -> attach media id to the draft. Fully deterministic under an injected client +
 * transport. Successful uploads count toward the `max_files` limit alongside `attachedCount`.
 */
export function useSubmissionFileUpload(opts: UseSubmissionFileUploadOptions) {
  const {
    assignment,
    attachedCount,
    courseId,
    mediaType = 'document',
    mediaPurpose = 'assignment_submission',
    attach,
  } = opts;

  const clientRef = useRef<SubmissionUploadClient>(opts.client ?? createDefaultUploadClient());
  const transportRef = useRef<UploadTransport>(opts.transport ?? xhrUploadTransport);
  const filesRef = useRef<Map<string, File>>(new Map());
  const [items, setItems] = useState<UploadItem[]>([]);

  const patch = useCallback((localId: string, next: Partial<UploadItem>) => {
    setItems((prev) => prev.map((it) => (it.localId === localId ? { ...it, ...next } : it)));
  }, []);

  const activeSuccessCount = useCallback(
    () => items.filter((i) => i.stage !== 'error').length,
    [items],
  );

  const run = useCallback(
    async (localId: string, file: File) => {
      try {
        patch(localId, { stage: 'requesting', error: undefined });
        const ticket = await clientRef.current.createTicket({
          filename: file.name,
          mime_type: file.type || 'application/octet-stream',
          size_bytes: file.size,
          type: mediaType,
          purpose: mediaPurpose,
          course_id: courseId ?? null,
          idempotency_key: idempotencyKey(file),
          role: 'attachment',
        });

        patch(localId, { stage: 'uploading', progress: 0 });
        await transportRef.current({
          instructions: ticket.upload,
          file,
          onProgress: (fraction) => patch(localId, { progress: fraction }),
        });

        patch(localId, { stage: 'finalizing', progress: 1 });
        await clientRef.current.finalize(ticket.media.id, ticket.upload_token);

        patch(localId, { stage: 'attaching', mediaId: ticket.media.id });
        await attach(ticket.media.id);

        patch(localId, { stage: 'done' });
      } catch (err) {
        patch(localId, {
          stage: 'error',
          error: err instanceof Error ? err.message : 'Upload failed',
        });
      }
    },
    [attach, courseId, mediaPurpose, mediaType, patch],
  );

  const addFiles = useCallback(
    (incoming: FileList | File[]) => {
      const list = Array.from(incoming);
      list.forEach((file) => {
        const currentCount = attachedCount + activeSuccessCount();
        const result = validateFile(file, assignment, currentCount);
        const localId = nextLocalId();
        filesRef.current.set(localId, file);
        if (!result.ok) {
          setItems((prev) => [
            ...prev,
            {
              localId,
              name: file.name,
              size: file.size,
              stage: 'error',
              progress: 0,
              error: result.message,
            },
          ]);
          return;
        }
        setItems((prev) => [
          ...prev,
          { localId, name: file.name, size: file.size, stage: 'validating', progress: 0 },
        ]);
        void run(localId, file);
      });
    },
    [activeSuccessCount, assignment, attachedCount, run],
  );

  const retry = useCallback(
    (localId: string) => {
      const file = filesRef.current.get(localId);
      if (!file) return;
      const currentCount = attachedCount + activeSuccessCount();
      const result = validateFile(file, assignment, currentCount - 1 < 0 ? 0 : currentCount - 1);
      if (!result.ok) {
        patch(localId, { stage: 'error', error: result.message });
        return;
      }
      patch(localId, { stage: 'validating', progress: 0, error: undefined });
      void run(localId, file);
    },
    [activeSuccessCount, assignment, attachedCount, patch, run],
  );

  const dismiss = useCallback((localId: string) => {
    filesRef.current.delete(localId);
    setItems((prev) => prev.filter((it) => it.localId !== localId));
  }, []);

  const isUploading = items.some(
    (i) => i.stage !== 'done' && i.stage !== 'error',
  );

  return { items, addFiles, retry, dismiss, isUploading };
}
