/**
 * React Query hooks for the gradebook. Mirrors the lib/authoring
 * versioning-api + versioning-hooks split: gradebook-api.ts holds the transport
 * and types; this module holds the query/mutation wiring and cache keys.
 */

import {
  keepPreviousData,
  useMutation,
  useQuery,
  type UseMutationResult,
  type UseQueryResult,
} from '@tanstack/react-query';

import {
  fetchGradebook,
  fetchGradebookCsv,
  type GradebookQuery,
  type GradebookRow,
  type Paginated,
} from './gradebook-api';

export const gradebookKeys = {
  all: ['gradebook'] as const,
  course: (publicId: string) => [...gradebookKeys.all, publicId] as const,
  page: (publicId: string, query: GradebookQuery) =>
    [...gradebookKeys.course(publicId), 'page', query] as const,
};

export interface UseGradebookOptions {
  enabled?: boolean;
}

/**
 * A page of gradebook rows. Keeps the previous page visible while the next one
 * loads so pagination/filter changes don't blank the table.
 */
export function useGradebook(
  publicId: string,
  query: GradebookQuery = {},
  options: UseGradebookOptions = {},
): UseQueryResult<Paginated<GradebookRow>> {
  return useQuery({
    queryKey: gradebookKeys.page(publicId, query),
    queryFn: () => fetchGradebook(publicId, query),
    enabled: (options.enabled ?? true) && Boolean(publicId),
    placeholderData: keepPreviousData,
  });
}

/** Mutation wrapper around the CSV export endpoint; resolves with the CSV blob. */
export function useGradebookExport(publicId: string): UseMutationResult<Blob, unknown, void> {
  return useMutation({
    mutationFn: () => fetchGradebookCsv(publicId),
  });
}
