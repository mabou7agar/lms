/**
 * C5 — Nested content-blocks data controller (React Query).
 *
 * `useLessonBlocks(lessonId, lessonVersion, enabled)` owns the list of a lesson's content blocks plus
 * the mutating actions (create / update / delete / duplicate / reorder / publish). It mirrors the
 * curriculum controller's contract:
 *   • server-authoritative reorder — persist the new order, then refetch so positions come from the
 *     server, threading the LESSON's `lock_version` as `expected_version`;
 *   • optimistic-concurrency — a stale-write (409) surfaces a non-destructive conflict instead of
 *     overwriting newer server state; the failed action is rolled back, never silently applied.
 *
 * The feature is gated behind the backend `authoring.blocks_enabled` flag: while off, the list
 * endpoint 404s, which the hook exposes as `featureDisabled` so the panel can hide itself.
 */
"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useQuery, useQueryClient, type QueryKey } from "@tanstack/react-query";
import { toast } from "@/components/ui/toast";
import { ApiRequestError } from "@/lib/api/client";
import { errorMessage } from "@/lib/api/errors";
import {
  StaleWriteError,
  createContentBlock,
  deleteContentBlock,
  duplicateContentBlock,
  listContentBlocks,
  reorderContentBlocks,
  setContentBlockPublish,
  updateContentBlock,
} from "../api";
import type { BlockKind, PublishState } from "../types";
import type { BlockContentI18n, ContentBlock, UpdateContentBlockInput } from "./types";

export function lessonBlocksKey(lessonId: string): QueryKey {
  return ["authoring", "lesson-blocks", lessonId];
}

/** Non-destructive conflict surfaced after a stale-write (409). */
export interface BlocksConflictState {
  currentVersion?: number;
}

export interface UseLessonBlocks {
  blocks: ContentBlock[];
  isLoading: boolean;
  isError: boolean;
  /** True when the list endpoint 404s — the backend blocks feature flag is off. */
  featureDisabled: boolean;
  /** True when the list endpoint 403s — the current user may not author this lesson's blocks. */
  permissionDenied: boolean;
  refetch: () => void;

  conflict: BlocksConflictState | null;
  reloadAfterConflict: () => void;
  dismissConflict: () => void;

  addBlock: (kind: BlockKind, content_i18n?: BlockContentI18n) => Promise<ContentBlock | undefined>;
  /** Resolves `true` on success; `false` on a conflict/failure (the failed edit was NOT applied). */
  editBlock: (blockId: string, input: Omit<UpdateContentBlockInput, "expected_version">) => Promise<boolean>;
  removeBlock: (blockId: string) => Promise<void>;
  duplicateBlock: (blockId: string) => Promise<void>;
  publishBlock: (blockId: string, state: PublishState) => Promise<void>;
  reorderBlocks: (orderedIds: string[]) => Promise<void>;
}

function reindex(blocks: ContentBlock[]): ContentBlock[] {
  return blocks.map((b, i) => ({ ...b, position: i }));
}
function orderBy(blocks: ContentBlock[], orderedIds: string[]): ContentBlock[] {
  const byId = new Map(blocks.map((b) => [b.id, b]));
  const ordered = orderedIds.map((id) => byId.get(id)).filter((x): x is ContentBlock => Boolean(x));
  const rest = blocks.filter((b) => !orderedIds.includes(b.id));
  return [...ordered, ...rest];
}

export function useLessonBlocks(lessonId: string, lessonVersion: number, enabled: boolean): UseLessonBlocks {
  const qc = useQueryClient();
  const key = lessonBlocksKey(lessonId);
  const [conflict, setConflict] = useState<BlocksConflictState | null>(null);

  // The parent LESSON is the optimistic-lock unit for reorders. Seed from the prop and keep it in
  // step: the reorder endpoint returns the lesson's advanced lock_version.
  const lessonVersionRef = useRef(lessonVersion);
  useEffect(() => {
    lessonVersionRef.current = lessonVersion;
  }, [lessonVersion]);

  const query = useQuery({
    queryKey: key,
    queryFn: () => listContentBlocks(lessonId),
    enabled,
    retry: false,
    staleTime: 15_000,
  });

  const featureDisabled = query.error instanceof ApiRequestError && query.error.status === 404;
  const permissionDenied = query.error instanceof ApiRequestError && query.error.status === 403;

  const handleError = useCallback((e: unknown) => {
    if (e instanceof StaleWriteError) {
      setConflict({ currentVersion: e.currentVersion });
      return;
    }
    toast.error(errorMessage(e, "Couldn't save your changes"));
  }, []);

  const invalidate = useCallback(() => qc.invalidateQueries({ queryKey: key }), [qc, key]);

  const addBlock = useCallback<UseLessonBlocks["addBlock"]>(
    async (kind, content_i18n) => {
      try {
        const created = await createContentBlock(lessonId, { type: kind, content_i18n });
        await invalidate();
        toast.success("Added");
        return created;
      } catch (e) {
        handleError(e);
        return undefined;
      }
    },
    [lessonId, invalidate, handleError],
  );

  const editBlock = useCallback<UseLessonBlocks["editBlock"]>(
    async (blockId, input) => {
      const current = qc.getQueryData<ContentBlock[]>(key)?.find((b) => b.id === blockId);
      try {
        await updateContentBlock(blockId, { ...input, expected_version: current?.lock_version });
        await invalidate();
        return true;
      } catch (e) {
        handleError(e);
        return false;
      }
    },
    [qc, key, invalidate, handleError],
  );

  const removeBlock = useCallback<UseLessonBlocks["removeBlock"]>(
    async (blockId) => {
      try {
        await deleteContentBlock(blockId);
        await invalidate();
        toast.success("Deleted");
      } catch (e) {
        handleError(e);
      }
    },
    [invalidate, handleError],
  );

  const duplicateBlock = useCallback<UseLessonBlocks["duplicateBlock"]>(
    async (blockId) => {
      try {
        await duplicateContentBlock(lessonId, blockId);
        await invalidate();
        toast.success("Added");
      } catch (e) {
        handleError(e);
      }
    },
    [lessonId, invalidate, handleError],
  );

  const publishBlock = useCallback<UseLessonBlocks["publishBlock"]>(
    async (blockId, state) => {
      const prev = qc.getQueryData<ContentBlock[]>(key);
      if (prev) qc.setQueryData<ContentBlock[]>(key, prev.map((b) => (b.id === blockId ? { ...b, publish_state: state } : b)));
      try {
        await setContentBlockPublish(blockId, state);
        await invalidate();
      } catch (e) {
        if (prev) qc.setQueryData<ContentBlock[]>(key, prev);
        handleError(e);
      }
    },
    [qc, key, invalidate, handleError],
  );

  const reorderBlocks = useCallback<UseLessonBlocks["reorderBlocks"]>(
    async (orderedIds) => {
      const prev = qc.getQueryData<ContentBlock[]>(key);
      if (prev) qc.setQueryData<ContentBlock[]>(key, reindex(orderBy(prev, orderedIds)));
      try {
        const { lock_version } = await reorderContentBlocks(lessonId, orderedIds, lessonVersionRef.current);
        lessonVersionRef.current = lock_version;
        await invalidate(); // server-authoritative: positions come back from the server
      } catch (e) {
        if (prev) qc.setQueryData<ContentBlock[]>(key, prev);
        handleError(e);
      }
    },
    [qc, key, lessonId, invalidate, handleError],
  );

  const reloadAfterConflict = useCallback(() => {
    setConflict(null);
    void query.refetch();
  }, [query]);
  const dismissConflict = useCallback(() => setConflict(null), []);

  return {
    blocks: query.data ?? [],
    isLoading: query.isPending && enabled,
    isError: query.isError && !featureDisabled && !permissionDenied,
    featureDisabled,
    permissionDenied,
    refetch: () => void query.refetch(),
    conflict,
    reloadAfterConflict,
    dismissConflict,
    addBlock,
    editBlock,
    removeBlock,
    duplicateBlock,
    publishBlock,
    reorderBlocks,
  };
}
