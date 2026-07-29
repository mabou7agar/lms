import { apiClient } from '@/lib/api/client';

/**
 * Direct-upload transport + media ticket client for learner submission files.
 *
 * Mirrors the Media platform's direct-upload pipeline (DirectUploadTicketResource):
 *   1. createTicket  -> POST /api/v1/media/assets            (returns provider instructions)
 *   2. transport     -> browser PUT/POSTs bytes STRAIGHT to the provider (never through our API)
 *   3. finalize      -> POST /api/v1/media/assets/{id}/finalize  (with the single-use upload token)
 * The resulting media public id is then attached to the draft through D3's assignments hook.
 *
 * Everything here is injectable so tests can supply a deterministic fake transport + client.
 */

export interface DirectUploadInstructions {
  url: string;
  method: string;
  headers: Record<string, string>;
  fields: Record<string, string>;
  expires_at: string;
}

export interface MediaAssetView {
  id: string;
  status: string;
  original_filename: string;
  mime_type: string;
  size_bytes: number;
  is_ready: boolean;
}

export interface UploadTicket {
  media: MediaAssetView;
  upload: DirectUploadInstructions;
  upload_token: string;
}

export interface CreateTicketInput {
  filename: string;
  mime_type: string;
  size_bytes: number;
  /** MediaType value (e.g. 'document', 'image', 'video'). See CONTRACT ASSUMPTIONS. */
  type: string;
  /** MediaPurpose value for a submission attachment. See CONTRACT ASSUMPTIONS. */
  purpose: string;
  /** Course public id — required by the media endpoint for authorization. */
  course_id?: string | null;
  idempotency_key: string;
  role?: 'primary' | 'attachment' | 'thumbnail';
}

export interface UploadTransportArgs {
  instructions: DirectUploadInstructions;
  file: File | Blob;
  onProgress?: (fraction: number) => void;
  signal?: AbortSignal;
}

export type UploadTransport = (args: UploadTransportArgs) => Promise<void>;

export interface SubmissionUploadClient {
  createTicket(input: CreateTicketInput): Promise<UploadTicket>;
  finalize(mediaId: string, uploadToken: string): Promise<MediaAssetView>;
}

/**
 * Default browser transport: streams bytes to the provider with real upload progress via XHR
 * (fetch has no upload-progress event). For a multipart provider (`fields` present) it builds a
 * FormData body with the file last, as S3-style POST policies require.
 */
export const xhrUploadTransport: UploadTransport = ({ instructions, file, onProgress, signal }) =>
  new Promise<void>((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    const method = (instructions.method || 'PUT').toUpperCase();
    xhr.open(method, instructions.url, true);

    const hasFields = instructions.fields && Object.keys(instructions.fields).length > 0;
    let body: XMLHttpRequestBodyInit;
    if (hasFields) {
      const form = new FormData();
      for (const [k, v] of Object.entries(instructions.fields)) form.append(k, v);
      form.append('file', file);
      body = form; // let the browser set the multipart boundary
    } else {
      for (const [k, v] of Object.entries(instructions.headers ?? {})) {
        xhr.setRequestHeader(k, v);
      }
      body = file;
    }

    xhr.upload.onprogress = (e) => {
      if (onProgress && e.lengthComputable) onProgress(e.loaded / e.total);
    };
    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        onProgress?.(1);
        resolve();
      } else {
        reject(new Error(`Upload failed (${xhr.status})`));
      }
    };
    xhr.onerror = () => reject(new Error('Upload network error'));
    xhr.onabort = () => reject(new DOMException('Upload aborted', 'AbortError'));
    if (signal) {
      if (signal.aborted) {
        xhr.abort();
        return;
      }
      signal.addEventListener('abort', () => xhr.abort(), { once: true });
    }
    xhr.send(body);
  });

/** Default client backed by the shared API client hitting the Media platform endpoints. */
export function createDefaultUploadClient(): SubmissionUploadClient {
  return {
    async createTicket(input) {
      const { data } = await apiClient.post<UploadTicket>('/v1/media/assets', input);
      return data;
    },
    async finalize(mediaId, uploadToken) {
      const { data } = await apiClient.post<{ data?: MediaAssetView } | MediaAssetView>(
        `/v1/media/assets/${mediaId}/finalize`,
        { upload_token: uploadToken },
      );
      // Tolerate both `{ data: {...} }` envelopes and bare resources.
      return (data as { data?: MediaAssetView }).data ?? (data as MediaAssetView);
    },
  };
}
