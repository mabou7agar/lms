/**
 * Course Builder — content versioning data controller (React Query, P2/W03).
 *
 * Queries + mutations for the version history. Every mutation invalidates the history on success
 * (optimistic refresh); restore/rollback also invalidate the curriculum, since they replace the
 * live draft. View state stays in the components — this layer is data only.
 */
"use client";

import { useMutation, useQuery, useQueryClient, type QueryKey } from "@tanstack/react-query";
import { curriculumKey } from "./hooks";
import {
  cloneVersion,
  createSnapshot,
  forkVersion,
  listVersions,
  restoreVersion,
  rollbackVersion,
  type CreateSnapshotInput,
  type ForkInput,
} from "./versioning-api";

export function versionsKey(courseId: string): QueryKey {
  return ["authoring", "versions", courseId];
}

export function useVersionHistory(courseId: string, page: number, enabled = true) {
  return useQuery({
    queryKey: [...versionsKey(courseId), page],
    queryFn: () => listVersions(courseId, page),
    enabled: !!courseId && enabled,
    staleTime: 0,
    placeholderData: (previous) => previous,
  });
}

function useHistoryInvalidation(courseId: string) {
  const qc = useQueryClient();
  return (draftChanged: boolean) => {
    qc.invalidateQueries({ queryKey: versionsKey(courseId) });
    if (draftChanged) qc.invalidateQueries({ queryKey: curriculumKey(courseId) });
  };
}

export function useCreateSnapshot(courseId: string) {
  const invalidate = useHistoryInvalidation(courseId);
  return useMutation({
    mutationFn: (input: CreateSnapshotInput) => createSnapshot(courseId, input),
    onSuccess: () => invalidate(false),
  });
}

export function useRestoreVersion(courseId: string) {
  const invalidate = useHistoryInvalidation(courseId);
  return useMutation({
    mutationFn: (versionId: string) => restoreVersion(versionId),
    onSuccess: () => invalidate(true),
  });
}

export function useRollbackVersion(courseId: string) {
  const invalidate = useHistoryInvalidation(courseId);
  return useMutation({
    mutationFn: (versionId: string) => rollbackVersion(versionId),
    onSuccess: () => invalidate(true),
  });
}

export function useCloneVersion(courseId: string) {
  const invalidate = useHistoryInvalidation(courseId);
  return useMutation({
    mutationFn: (args: { versionId: string; label?: string | null }) => cloneVersion(args.versionId, args.label),
    onSuccess: () => invalidate(false),
  });
}

export function useForkVersion(courseId: string) {
  const invalidate = useHistoryInvalidation(courseId);
  return useMutation({
    mutationFn: (args: { versionId: string; input: ForkInput }) => forkVersion(args.versionId, args.input),
    onSuccess: () => invalidate(false),
  });
}
