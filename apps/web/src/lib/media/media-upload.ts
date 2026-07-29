/**
 * Instructor Media Library — direct browser upload transport + orchestrator (P2/W04).
 *
 * CRITICAL: large files NEVER stream through Next/Laravel. The browser uploads the bytes DIRECTLY
 * to the provider (Mux / S3) using the backend-issued `DirectUploadInstructions`, with XHR upload
 * progress. The flow is: create-upload (POST backend) → PUT/POST bytes to provider → finalize
 * (POST backend) → poll status until processing resolves to ready/failed.
 *
 * The provider transport is injectable (`UploadTransport`) so components and tests can supply a
 * fake; the same seam keeps the orchestrator deterministic under test.
 */
import {
  createDirectUpload,
  finalizeUpload,
  getMedia,
  type CreateDirectUploadInput,
  type DirectUploadInstructions,
  type DirectUploadTicket,
  type MediaAsset,
} from "./media-api";

export interface UploadProgress {
  loaded: number;
  total: number;
  percent: number;
}

/** Phases surfaced to the UI while a single file moves through the pipeline. */
export type UploadPhase = "creating" | "uploading" | "finalizing" | "processing" | "ready" | "failed";

export interface UploadTransportArgs {
  instructions: DirectUploadInstructions;
  file: Blob;
  onProgress?: (progress: UploadProgress) => void;
  signal?: AbortSignal;
}

export type UploadTransport = (args: UploadTransportArgs) => Promise<void>;

/**
 * Real XHR transport. Uses `xhr.upload.onprogress` for byte-level progress (fetch cannot report
 * upload progress). PUT sends raw bytes (Mux / signed S3 PUT); POST with fields sends a multipart
 * form with the provider fields first and the file appended last (S3 presigned POST contract).
 */
export const xhrUploadTransport: UploadTransport = ({ instructions, file, onProgress, signal }) =>
  new Promise<void>((resolve, reject) => {
    const method = (instructions.method || "PUT").toUpperCase();
    const xhr = new XMLHttpRequest();
    xhr.open(method, instructions.url, true);

    for (const [key, value] of Object.entries(instructions.headers ?? {})) {
      xhr.setRequestHeader(key, value);
    }

    xhr.upload.onprogress = (event) => {
      if (!onProgress) return;
      const total = event.lengthComputable ? event.total : file.size;
      const percent = total > 0 ? Math.round((event.loaded / total) * 100) : 0;
      onProgress({ loaded: event.loaded, total, percent });
    };

    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        onProgress?.({ loaded: file.size, total: file.size, percent: 100 });
        resolve();
      } else {
        reject(new Error(`Upload failed (HTTP ${xhr.status}).`));
      }
    };
    xhr.onerror = () => reject(new Error("Network error during upload."));
    xhr.onabort = () => reject(new DOMException("Upload aborted.", "AbortError"));

    if (signal) {
      if (signal.aborted) {
        xhr.abort();
        return;
      }
      signal.addEventListener("abort", () => xhr.abort(), { once: true });
    }

    const fields = instructions.fields ?? {};
    if (method === "POST" && Object.keys(fields).length > 0) {
      const form = new FormData();
      for (const [key, value] of Object.entries(fields)) form.append(key, value);
      form.append("file", file);
      xhr.send(form);
    } else {
      xhr.send(file);
    }
  });

export interface DirectUploadDeps {
  transport?: UploadTransport;
  createUpload?: typeof createDirectUpload;
  finalize?: typeof finalizeUpload;
  fetchStatus?: typeof getMedia;
}

export interface DirectUploadHandlers {
  onPhase?: (phase: UploadPhase, asset?: MediaAsset) => void;
  onProgress?: (progress: UploadProgress) => void;
  signal?: AbortSignal;
  /** Poll interval (ms) while the asset is processing. Set 0 to skip polling. */
  pollIntervalMs?: number;
  /** Upper bound on poll attempts before returning the last-known (still processing) asset. */
  maxPolls?: number;
}

/**
 * Run one file end-to-end: create → direct-upload (with progress) → finalize → poll to ready.
 * Returns the last-known asset (ready, failed, or still processing if polling exhausts). Throws
 * only on a transport/network/token error, which the caller surfaces as a retryable failure.
 */
export async function performDirectUpload(
  input: CreateDirectUploadInput,
  file: Blob,
  handlers: DirectUploadHandlers = {},
  deps: DirectUploadDeps = {},
): Promise<MediaAsset> {
  const create = deps.createUpload ?? createDirectUpload;
  const finalize = deps.finalize ?? finalizeUpload;
  const fetchStatus = deps.fetchStatus ?? getMedia;
  const transport = deps.transport ?? xhrUploadTransport;
  const { onPhase, onProgress, signal, pollIntervalMs = 2000, maxPolls = 150 } = handlers;

  onPhase?.("creating");
  const ticket: DirectUploadTicket = await create(input);

  onPhase?.("uploading", ticket.media);
  await transport({ instructions: ticket.upload, file, onProgress, signal });

  onPhase?.("finalizing", ticket.media);
  let asset = await finalize(ticket.media.id, ticket.upload_token);

  if (asset.is_ready) {
    onPhase?.("ready", asset);
    return asset;
  }
  if (asset.status === "failed") {
    onPhase?.("failed", asset);
    return asset;
  }

  onPhase?.("processing", asset);
  for (let attempt = 0; attempt < maxPolls && pollIntervalMs > 0; attempt += 1) {
    if (signal?.aborted) break;
    await delay(pollIntervalMs, signal);
    asset = await fetchStatus(ticket.media.id);
    if (asset.is_ready) {
      onPhase?.("ready", asset);
      return asset;
    }
    if (asset.status === "failed") {
      onPhase?.("failed", asset);
      return asset;
    }
    onPhase?.("processing", asset);
  }
  return asset;
}

function delay(ms: number, signal?: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(resolve, ms);
    signal?.addEventListener(
      "abort",
      () => {
        clearTimeout(timer);
        reject(new DOMException("Aborted.", "AbortError"));
      },
      { once: true },
    );
  });
}

/** Idempotency key for create-upload (retried creation is a backend no-op by (actor, key)). */
export function newIdempotencyKey(): string {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) return crypto.randomUUID();
  return `up_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 10)}`;
}
