/**
 * Instructor Media Library — typed API client (P2/W04).
 *
 * Wraps the frozen Media instructor endpoints (`/api/v1/media/*`) reached through the same
 * same-origin BFF proxy as the rest of the app (`@/lib/api/client`). Every call hits the real
 * backend and surfaces `ApiRequestError` on failure. Field names mirror the backend Resources
 * verbatim (MediaAssetResource / DirectUploadTicketResource / MediaAttachmentResource /
 * MediaCaptionResource); the public `id` is always the asset/caption public id — internal ids and
 * provider/storage identifiers are never exposed by the backend.
 */
import { api } from "@/lib/api/client";
import type { Paginated } from "@/types/api";

/** Media kinds accepted by the ingestion pipeline. Only `video` is exercised by the frozen slice;
 *  the others are declared for the attachment picker / type filter and reconciled by the backend
 *  `MediaType` enum. */
export type MediaType = "video" | "audio" | "image" | "document";

/** Ingestion provider the asset was routed to. */
export type MediaProvider = "mux" | "s3" | "fake" | "external";

/**
 * Asset lifecycle. `created` → `waiting_for_upload` → (`uploaded`) → `processing` → `ready`, with
 * `failed` reachable from processing and `deleted` after soft-delete. The backend also exposes the
 * derived `is_ready` boolean; UI should prefer `is_ready` over string comparison for playability.
 */
export type MediaStatus =
  | "created"
  | "waiting_for_upload"
  | "uploaded"
  | "processing"
  | "ready"
  | "failed"
  | "deleted";

/** Attachment role vocabulary shared by attach/detach + direct-upload creation. */
export type MediaRole = "primary" | "attachment" | "thumbnail";

export type CaptionFormat = "vtt" | "srt";
export type CaptionStatus = "pending" | "ready" | "failed";

/** Client-safe asset view — exact shape of `MediaAssetResource`. */
export interface MediaAsset {
  id: string;
  type: MediaType;
  status: MediaStatus;
  purpose: string;
  provider: MediaProvider;
  original_filename: string | null;
  mime_type: string | null;
  size_bytes: number | null;
  duration_seconds: number | null;
  width: number | null;
  height: number | null;
  processing_progress: number;
  is_ready: boolean;
  failure_code: string | null;
  failure_message: string | null;
  created_at: string | null;
  updated_at: string | null;
}

/** Opaque provider upload instructions (the `upload` block of `DirectUploadTicketResource`). The
 *  browser PUTs/POSTs the bytes directly to `url` — the provider ref is intentionally NOT exposed. */
export interface DirectUploadInstructions {
  url: string;
  /** "PUT" (Mux / signed S3 PUT) or "POST" (S3 presigned form POST). */
  method: string;
  headers: Record<string, string>;
  fields: Record<string, string>;
  expires_at: string;
}

/** Exact shape of `DirectUploadTicketResource`. */
export interface DirectUploadTicket {
  media: MediaAsset;
  upload: DirectUploadInstructions;
  upload_token: string;
}

/** Client-safe usage record — exact shape of `MediaAttachmentResource`. */
export interface MediaAttachment {
  id: string;
  attachable_type: string;
  attachable_id: number;
  role: string;
  created_at: string | null;
}

/** Client-safe caption view — exact shape of `MediaCaptionResource`. */
export interface MediaCaption {
  id: string;
  language: string;
  label: string;
  format: CaptionFormat;
  status: CaptionStatus;
  created_at: string | null;
}

export interface MediaListFilters {
  type?: MediaType;
  status?: MediaStatus;
  courseId?: string;
  page?: number;
  perPage?: number;
}

/** Body for `POST /assets` — matches `CreateDirectUploadRequest`. */
export interface CreateDirectUploadInput {
  type: MediaType;
  purpose: string;
  filename: string;
  mime_type: string;
  size_bytes: number;
  course_id?: string | null;
  idempotency_key: string;
  role?: MediaRole;
}

/** Body for `POST /assets/{id}/attachments` — matches `AttachMediaRequest`. */
export interface AttachMediaInput {
  attachable_type: string;
  attachable_id: number;
  role?: MediaRole;
  course_id?: string | null;
}

/** Body for `DELETE /assets/{id}/attachments` — matches `DetachMediaRequest`. */
export interface DetachMediaInput {
  attachable_type: string;
  attachable_id: number;
}

/** Body for `POST /assets/{id}/captions` — matches `StoreCaptionRequest`. */
export interface AddCaptionInput {
  language: string;
  label: string;
  format?: CaptionFormat;
  storage_key?: string | null;
  provider_ref?: string | null;
}

const BASE = "media";

/** Paginated library of the actor's own media, newest first. */
export function listMedia(filters: MediaListFilters = {}): Promise<Paginated<MediaAsset>> {
  const params = new URLSearchParams();
  params.set("page", String(filters.page ?? 1));
  params.set("per_page", String(filters.perPage ?? 20));
  if (filters.type) params.set("type", filters.type);
  if (filters.status) params.set("status", filters.status);
  if (filters.courseId) params.set("course_id", filters.courseId);
  return api.get<Paginated<MediaAsset>>(`${BASE}/assets?${params.toString()}`);
}

/** Current status of a single asset (used for polling processing → ready). */
export function getMedia(id: string): Promise<MediaAsset> {
  return api.data<MediaAsset>(`${BASE}/assets/${id}`);
}

/** Create a direct-upload slot: returns provider instructions + single-use finalize token. */
export function createDirectUpload(input: CreateDirectUploadInput): Promise<DirectUploadTicket> {
  return api.data<DirectUploadTicket>(`${BASE}/assets`, { method: "POST", body: input });
}

/** Confirm an upload; spends the single-use token and reads authoritative provider state. */
export function finalizeUpload(id: string, uploadToken: string): Promise<MediaAsset> {
  return api.data<MediaAsset>(`${BASE}/assets/${id}/finalize`, {
    method: "POST",
    body: { upload_token: uploadToken },
  });
}

/** Re-drive ingestion for a failed asset. */
export function retryMedia(id: string): Promise<MediaAsset> {
  return api.data<MediaAsset>(`${BASE}/assets/${id}/retry`, { method: "POST" });
}

/** Soft-delete an asset. `force` deletes even when it is still attached (MediaInUseException). */
export function deleteMedia(id: string, force = false): Promise<void> {
  return api.del<void>(`${BASE}/assets/${id}${force ? "?force=1" : ""}`);
}

/** Attach a ready asset to another context's entity by scalar polymorphic reference. */
export function attachMedia(id: string, input: AttachMediaInput): Promise<MediaAttachment> {
  return api.data<MediaAttachment>(`${BASE}/assets/${id}/attachments`, { method: "POST", body: input });
}

/** Remove a usage record for an asset. */
export function detachMedia(id: string, input: DetachMediaInput): Promise<void> {
  return api.del<void>(`${BASE}/assets/${id}/attachments`, { body: input });
}

/** Caption/subtitle tracks for an asset (non-paginated collection). */
export function listCaptions(id: string): Promise<MediaCaption[]> {
  return api.data<MediaCaption[]>(`${BASE}/assets/${id}/captions`);
}

/** Add a caption track (metadata only; the platform never transcribes). */
export function addCaption(id: string, input: AddCaptionInput): Promise<MediaCaption> {
  return api.data<MediaCaption>(`${BASE}/assets/${id}/captions`, { method: "POST", body: input });
}

/** Remove a caption track. */
export function deleteCaption(id: string, captionId: string): Promise<void> {
  return api.del<void>(`${BASE}/assets/${id}/captions/${captionId}`);
}
