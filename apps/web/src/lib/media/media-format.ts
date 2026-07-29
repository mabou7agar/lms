/**
 * Instructor Media Library — presentation helpers (P2/W04).
 *
 * Pure functions: byte/duration formatting, lifecycle → UI-phase collapsing, badge variant
 * mapping, and the manage-permission gate. No React, no i18n — callers pass translated strings.
 */
import type { AuthUser } from "@/types/api";
import type { MediaAsset, MediaStatus, MediaType } from "./media-api";

/**
 * The four states the UI actually renders, collapsed from the richer backend lifecycle:
 * - `awaiting`   — created / waiting_for_upload (bytes not yet uploaded)
 * - `processing` — uploaded / processing (provider is transcoding)
 * - `ready`      — playable
 * - `failed`     — ingestion failed (retryable)
 */
export type MediaPhase = "awaiting" | "processing" | "ready" | "failed";

export function mediaPhase(asset: Pick<MediaAsset, "status" | "is_ready">): MediaPhase {
  if (asset.is_ready || asset.status === "ready") return "ready";
  if (asset.status === "failed") return "failed";
  if (asset.status === "processing" || asset.status === "uploaded") return "processing";
  return "awaiting";
}

export type BadgeVariant = "success" | "secondary" | "destructive" | "outline" | "warning";

export function phaseBadgeVariant(phase: MediaPhase): BadgeVariant {
  switch (phase) {
    case "ready":
      return "success";
    case "processing":
      return "secondary";
    case "failed":
      return "destructive";
    default:
      return "outline";
  }
}

/** True while a status warrants active polling (not yet a terminal ready/failed). */
export function isPolling(status: MediaStatus): boolean {
  return status === "created" || status === "waiting_for_upload" || status === "uploaded" || status === "processing";
}

/** Human-readable byte size. Returns an em dash for null/unknown. */
export function formatBytes(bytes: number | null | undefined): string {
  if (bytes == null || bytes < 0) return "—";
  if (bytes === 0) return "0 B";
  const units = ["B", "KB", "MB", "GB", "TB"];
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
  const value = bytes / 1024 ** i;
  return `${value >= 10 || i === 0 ? Math.round(value) : parseFloat(value.toFixed(1))} ${units[i]}`;
}

/** Duration in seconds → `h:mm:ss` / `m:ss`. Em dash for null/unknown. */
export function formatDuration(seconds: number | null | undefined): string {
  if (seconds == null || seconds < 0) return "—";
  const s = Math.floor(seconds % 60);
  const m = Math.floor((seconds / 60) % 60);
  const h = Math.floor(seconds / 3600);
  const pad = (n: number) => String(n).padStart(2, "0");
  return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
}

/** Infer the ingestion media type from a File's MIME type. Defaults to `document`. */
export function typeFromMime(mime: string): MediaType {
  if (mime.startsWith("video/")) return "video";
  if (mime.startsWith("audio/")) return "audio";
  if (mime.startsWith("image/")) return "image";
  return "document";
}

/** Roles permitted to manage (upload / delete / caption / attach) media. */
export const MEDIA_MANAGE_ROLES = ["instructor", "admin"] as const;

export function canManageMedia(user: Pick<AuthUser, "roles"> | null | undefined): boolean {
  if (!user) return false;
  const allowed = MEDIA_MANAGE_ROLES as readonly string[];
  return user.roles.some((role) => allowed.includes(role));
}
