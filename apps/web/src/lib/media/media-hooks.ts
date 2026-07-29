/**
 * Instructor Media Library — data controller (React Query, P2/W04).
 *
 * Queries + mutations for the media library. Mutations invalidate the affected caches on success;
 * the single asset query self-polls while it is in a non-terminal (processing) state so a details
 * drawer / preview transitions to ready without user action. `useMediaUploader` owns the
 * client-side upload state machine (create → direct-upload → finalize → poll), delegating the
 * provider bytes to the injectable transport in `media-upload.ts`. View state stays in components.
 */
"use client";

import { useCallback, useReducer } from "react";
import {
  useMutation,
  useQuery,
  useQueryClient,
  type QueryKey,
} from "@tanstack/react-query";
import { errorMessage } from "@/lib/api/errors";
import {
  addCaption,
  attachMedia,
  deleteCaption,
  deleteMedia,
  detachMedia,
  getMedia,
  listCaptions,
  listMedia,
  retryMedia,
  type AddCaptionInput,
  type AttachMediaInput,
  type CreateDirectUploadInput,
  type DetachMediaInput,
  type MediaAsset,
  type MediaListFilters,
} from "./media-api";
import { isPolling, typeFromMime } from "./media-format";
import {
  newIdempotencyKey,
  performDirectUpload,
  type UploadPhase,
  type UploadProgress,
  type UploadTransport,
} from "./media-upload";

export const mediaKeys = {
  all: ["media"] as const,
  lists: () => [...mediaKeys.all, "list"] as const,
  list: (filters: MediaListFilters): QueryKey => [...mediaKeys.lists(), filters],
  asset: (id: string): QueryKey => [...mediaKeys.all, "asset", id],
  captions: (id: string): QueryKey => [...mediaKeys.all, "captions", id],
};

/** Paginated library. `placeholderData` keeps the current page visible while the next loads. */
export function useMediaLibrary(filters: MediaListFilters, enabled = true) {
  return useQuery({
    queryKey: mediaKeys.list(filters),
    queryFn: () => listMedia(filters),
    enabled,
    placeholderData: (previous) => previous,
  });
}

/** Single asset status. Polls every `pollMs` while non-terminal, then stops. */
export function useMediaAsset(id: string | null, pollMs = 4000, enabled = true) {
  return useQuery({
    queryKey: mediaKeys.asset(id ?? "none"),
    queryFn: () => getMedia(id as string),
    enabled: enabled && !!id,
    refetchInterval: (query) => {
      const data = query.state.data as MediaAsset | undefined;
      return data && isPolling(data.status) ? pollMs : false;
    },
  });
}

export function useCaptions(id: string | null, enabled = true) {
  return useQuery({
    queryKey: mediaKeys.captions(id ?? "none"),
    queryFn: () => listCaptions(id as string),
    enabled: enabled && !!id,
  });
}

function useInvalidateLibrary() {
  const qc = useQueryClient();
  return useCallback(() => qc.invalidateQueries({ queryKey: mediaKeys.lists() }), [qc]);
}

export function useRetryMedia() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => retryMedia(id),
    onSuccess: (asset) => {
      qc.invalidateQueries({ queryKey: mediaKeys.lists() });
      qc.setQueryData(mediaKeys.asset(asset.id), asset);
    },
  });
}

export function useDeleteMedia() {
  const invalidate = useInvalidateLibrary();
  return useMutation({
    mutationFn: (args: { id: string; force?: boolean }) => deleteMedia(args.id, args.force ?? false),
    onSuccess: invalidate,
  });
}

export function useAttachMedia() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (args: { id: string; input: AttachMediaInput }) => attachMedia(args.id, args.input),
    onSuccess: (_data, args) => qc.invalidateQueries({ queryKey: mediaKeys.asset(args.id) }),
  });
}

export function useDetachMedia() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (args: { id: string; input: DetachMediaInput }) => detachMedia(args.id, args.input),
    onSuccess: (_data, args) => qc.invalidateQueries({ queryKey: mediaKeys.asset(args.id) }),
  });
}

export function useAddCaption(mediaId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: AddCaptionInput) => addCaption(mediaId, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: mediaKeys.captions(mediaId) }),
  });
}

export function useDeleteCaption(mediaId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (captionId: string) => deleteCaption(mediaId, captionId),
    onSuccess: () => qc.invalidateQueries({ queryKey: mediaKeys.captions(mediaId) }),
  });
}

/* --------------------------------------------------------------------------------------------- */
/* Upload state machine                                                                          */
/* --------------------------------------------------------------------------------------------- */

export interface UploadItem {
  id: string;
  file: File;
  phase: UploadPhase;
  progress: number;
  error: string | null;
  asset: MediaAsset | null;
}

export interface MediaUploaderOptions {
  /** Backend purpose the file is uploaded for (e.g. `lesson_video`). */
  purpose: string;
  /** Optional course public id to scope the upload. */
  courseId?: string | null;
  /** Optional attachment role hint. */
  role?: CreateDirectUploadInput["role"];
  /** Injected transport (tests supply a fake; production defaults to XHR). */
  transport?: UploadTransport;
}

type Action =
  | { type: "enqueue"; item: UploadItem }
  | { type: "phase"; id: string; phase: UploadPhase; asset?: MediaAsset }
  | { type: "progress"; id: string; percent: number }
  | { type: "error"; id: string; message: string }
  | { type: "remove"; id: string }
  | { type: "clear" };

function reducer(state: UploadItem[], action: Action): UploadItem[] {
  switch (action.type) {
    case "enqueue":
      return [action.item, ...state];
    case "phase":
      return state.map((item) =>
        item.id === action.id
          ? { ...item, phase: action.phase, error: null, asset: action.asset ?? item.asset }
          : item,
      );
    case "progress":
      return state.map((item) => (item.id === action.id ? { ...item, progress: action.percent } : item));
    case "error":
      return state.map((item) =>
        item.id === action.id ? { ...item, phase: "failed", error: action.message } : item,
      );
    case "remove":
      return state.filter((item) => item.id !== action.id);
    case "clear":
      return state.filter((item) => item.phase !== "ready" && item.phase !== "failed");
    default:
      return state;
  }
}

/**
 * Owns per-file upload state. `enqueue` starts uploads immediately; `retry` re-runs a failed item;
 * the library list is invalidated whenever an item reaches ready so the new asset appears.
 */
export function useMediaUploader(options: MediaUploaderOptions) {
  const [items, dispatch] = useReducer(reducer, []);
  const invalidate = useInvalidateLibrary();

  const run = useCallback(
    async (item: UploadItem) => {
      const input: CreateDirectUploadInput = {
        type: typeFromMime(item.file.type),
        purpose: options.purpose,
        filename: item.file.name,
        mime_type: item.file.type || "application/octet-stream",
        size_bytes: item.file.size,
        course_id: options.courseId ?? null,
        idempotency_key: item.id,
        role: options.role,
      };

      try {
        const asset = await performDirectUpload(
          input,
          item.file,
          {
            onPhase: (phase, a) => dispatch({ type: "phase", id: item.id, phase, asset: a }),
            onProgress: (p: UploadProgress) => dispatch({ type: "progress", id: item.id, percent: p.percent }),
          },
          { transport: options.transport },
        );
        if (asset.is_ready || asset.status === "processing") invalidate();
      } catch (error) {
        dispatch({ type: "error", id: item.id, message: errorMessage(error, "Upload failed.") });
      }
    },
    [invalidate, options.courseId, options.purpose, options.role, options.transport],
  );

  const enqueue = useCallback(
    (files: File[]) => {
      for (const file of files) {
        const item: UploadItem = {
          id: newIdempotencyKey(),
          file,
          phase: "creating",
          progress: 0,
          error: null,
          asset: null,
        };
        dispatch({ type: "enqueue", item });
        void run(item);
      }
    },
    [run],
  );

  const retry = useCallback(
    (id: string) => {
      const item = items.find((entry) => entry.id === id);
      if (!item) return;
      dispatch({ type: "phase", id, phase: "creating" });
      void run({ ...item, phase: "creating", progress: 0, error: null });
    },
    [items, run],
  );

  const remove = useCallback((id: string) => dispatch({ type: "remove", id }), []);
  const clear = useCallback(() => dispatch({ type: "clear" }), []);

  return { items, enqueue, retry, remove, clear };
}
