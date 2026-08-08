"use client";

/**
 * Course reviews — React Query data controller.
 *
 * Mirrors `lib/catalog/hooks.ts` + `lib/assignments/assignments-hooks.ts`: queries + mutations with
 * plain invalidation on success (nothing the server can reject is applied optimistically). The one
 * exception is the idempotent "helpful" vote, which patches the new count straight into the cached
 * page — a safe, monotonic update the endpoint guarantees.
 */
import { useMutation, useQuery, useQueryClient, type QueryKey } from "@tanstack/react-query";
import {
  createReview,
  deleteReview,
  listReviews,
  markReviewHelpful,
  reportReview,
  updateReview,
  type CreateReviewInput,
  type ReportInput,
  type ReviewListParams,
  type ReviewListResponse,
  type ReviewSort,
  type UpdateReviewInput,
} from "./reviews-api";

export function reviewsKey(course: string): QueryKey {
  return ["community", "reviews", course];
}

export function useReviews(course: string, sort: ReviewSort, page: number, perPage = 10) {
  const params: ReviewListParams = { sort, page, per_page: perPage };
  return useQuery({
    queryKey: [...reviewsKey(course), { sort, page, perPage }],
    queryFn: () => listReviews(course, params),
    enabled: Boolean(course),
    placeholderData: (previous) => previous,
    staleTime: 30_000,
  });
}

export function useCreateReview(course: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: CreateReviewInput) => createReview(course, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: reviewsKey(course) }),
  });
}

export function useUpdateReview(course: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (args: { review: string; input: UpdateReviewInput }) => updateReview(args.review, args.input),
    onSuccess: () => qc.invalidateQueries({ queryKey: reviewsKey(course) }),
  });
}

export function useDeleteReview(course: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (review: string) => deleteReview(review),
    onSuccess: () => qc.invalidateQueries({ queryKey: reviewsKey(course) }),
  });
}

/** Helpful vote — patch the returned count into every cached page that holds this review. */
export function useMarkReviewHelpful(course: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (review: string) => markReviewHelpful(review),
    onSuccess: (result, review) => {
      qc.setQueriesData<ReviewListResponse>({ queryKey: reviewsKey(course) }, (prev) => {
        if (!prev) return prev;
        return {
          ...prev,
          data: prev.data.map((r) =>
            r.id === review ? { ...r, helpful_count: result.helpful_count } : r,
          ),
        };
      });
    },
  });
}

export function useReportReview() {
  return useMutation({
    mutationFn: (args: { review: string; input: ReportInput }) => reportReview(args.review, args.input),
  });
}
