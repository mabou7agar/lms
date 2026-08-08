"use client";

/**
 * Course discussion forum — React Query data controller.
 *
 * Mirrors `lib/catalog/hooks.ts`: thread-list + thread-detail queries, mutations with plain
 * invalidation on success. View state stays in the components; this layer is data only.
 */
import { useMutation, useQuery, useQueryClient, type QueryKey } from "@tanstack/react-query";
import {
  createThread,
  getThread,
  listThreads,
  replyToThread,
  reportPost,
  reportThread,
  type CreatePostInput,
  type CreateThreadInput,
  type ThreadListParams,
} from "./forum-api";
import type { ReportInput } from "./reviews-api";

export function threadsKey(course: string): QueryKey {
  return ["community", "threads", course];
}
export function threadKey(thread: string): QueryKey {
  return ["community", "thread", thread];
}

export function useThreads(course: string, opts: { q?: string; page?: number } = {}) {
  const params: ThreadListParams = { q: opts.q, page: opts.page };
  return useQuery({
    queryKey: [...threadsKey(course), params],
    queryFn: () => listThreads(course, params),
    enabled: Boolean(course),
    placeholderData: (previous) => previous,
    staleTime: 15_000,
  });
}

export function useThread(thread: string | null, page = 1) {
  return useQuery({
    queryKey: [...threadKey(thread ?? "none"), page],
    queryFn: () => getThread(thread as string, page),
    enabled: Boolean(thread),
    staleTime: 10_000,
  });
}

export function useCreateThread(course: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: CreateThreadInput) => createThread(course, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: threadsKey(course) }),
  });
}

export function useReplyToThread(course: string, thread: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: CreatePostInput) => replyToThread(thread, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: threadKey(thread) });
      qc.invalidateQueries({ queryKey: threadsKey(course) });
    },
  });
}

export function useReportThread() {
  return useMutation({
    mutationFn: (args: { thread: string; input: ReportInput }) => reportThread(args.thread, args.input),
  });
}

export function useReportPost() {
  return useMutation({
    mutationFn: (args: { post: string; input: ReportInput }) => reportPost(args.post, args.input),
  });
}
