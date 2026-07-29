import type { MediaAsset, MediaCaption } from "@/lib/media/media-api";
import type { Paginated } from "@/types/api";

export function makeAsset(overrides: Partial<MediaAsset> = {}): MediaAsset {
  return {
    id: "m1",
    type: "video",
    status: "ready",
    purpose: "lesson_video",
    provider: "fake",
    original_filename: "lecture.mp4",
    mime_type: "video/mp4",
    size_bytes: 10 * 1024 * 1024,
    duration_seconds: 125,
    width: 1280,
    height: 720,
    processing_progress: 100,
    is_ready: true,
    failure_code: null,
    failure_message: null,
    created_at: "2026-07-20T10:00:00Z",
    updated_at: "2026-07-20T10:05:00Z",
    ...overrides,
  };
}

export function makeCaption(overrides: Partial<MediaCaption> = {}): MediaCaption {
  return {
    id: "cap1",
    language: "en",
    label: "English",
    format: "vtt",
    status: "ready",
    created_at: "2026-07-20T10:00:00Z",
    ...overrides,
  };
}

export function makePage(assets: MediaAsset[], lastPage = 1): Paginated<MediaAsset> {
  return {
    data: assets,
    meta: { current_page: 1, per_page: 20, total: assets.length, last_page: lastPage },
    links: { first: null, last: null, prev: null, next: null },
  };
}
