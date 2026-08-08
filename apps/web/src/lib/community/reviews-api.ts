/**
 * Course reviews — typed API client.
 *
 * Wraps the public/authenticated review endpoints reached through the same-origin BFF proxy
 * (`@/lib/api/client`). Paths, payload keys and resource fields are matched VERBATIM against
 * `app/Domains/Reviews/Http/{Controllers,Resources}` and `Platform/Shared/Moderation/Enums/ReportReason`.
 *
 * The index is public; every write requires an authenticated session (enforced server-side).
 * The listing returns the shared success envelope with a NON-paginated `meta.aggregate` +
 * `meta.pagination` block, so it is modelled explicitly here rather than as `Paginated<T>`.
 */
import { api } from "@/lib/api/client";

/** ReportReason.php — shared across reviews, Q&A and forum reporting. */
export type ReportReason = "spam" | "offensive" | "harassment" | "off_topic" | "other";
export const REPORT_REASONS: readonly ReportReason[] = [
  "spam",
  "offensive",
  "harassment",
  "off_topic",
  "other",
] as const;

/** CourseReviewAggregateResource — the per-course rating summary. */
export interface ReviewAggregate {
  reviews_count: number;
  average_rating: number;
  distribution: Record<"1" | "2" | "3" | "4" | "5", number>;
}

/** CourseReviewResource — one published review (author identity is intentionally not exposed). */
export interface Review {
  id: string;
  rating: number;
  body: string | null;
  status: string;
  verified: boolean;
  helpful_count: number;
  instructor_response: string | null;
  responded_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export type ReviewSort = "recent" | "helpful" | "rating";

export interface ReviewListParams {
  sort?: ReviewSort;
  page?: number;
  per_page?: number;
}

/** GET index envelope: `{ data: Review[], meta: { aggregate, pagination } }`. */
export interface ReviewListResponse {
  data: Review[];
  meta: {
    aggregate: ReviewAggregate;
    pagination: { current_page: number; per_page: number; total: number; last_page: number };
  };
}

export interface CreateReviewInput {
  rating: number;
  body?: string | null;
}

export interface UpdateReviewInput {
  rating?: number;
  body?: string | null;
}

export interface ReportInput {
  reason: ReportReason;
  note?: string | null;
}

function listQuery(params: ReviewListParams): string {
  const p = new URLSearchParams();
  if (params.sort) p.set("sort", params.sort);
  if (params.page) p.set("page", String(params.page));
  if (params.per_page) p.set("per_page", String(params.per_page));
  const s = p.toString();
  return s ? `?${s}` : "";
}

/** GET /api/v1/courses/{course}/reviews — public, paginated, with aggregate + distribution. */
export const listReviews = (course: string, params: ReviewListParams = {}): Promise<ReviewListResponse> =>
  api.get<ReviewListResponse>(`courses/${course}/reviews${listQuery(params)}`);

/** POST /api/v1/courses/{course}/reviews — create the caller's review. */
export const createReview = (course: string, input: CreateReviewInput): Promise<Review> =>
  api.data<Review>(`courses/${course}/reviews`, { method: "POST", body: input });

/** PATCH /api/v1/reviews/{review} — update the caller's own review. */
export const updateReview = (review: string, input: UpdateReviewInput): Promise<Review> =>
  api.data<Review>(`reviews/${review}`, { method: "PATCH", body: input });

/** DELETE /api/v1/reviews/{review} — delete own review. */
export const deleteReview = (review: string): Promise<void> => api.del<void>(`reviews/${review}`);

/** POST /api/v1/reviews/{review}/helpful — mark helpful (idempotent); returns the new count. */
export const markReviewHelpful = (review: string): Promise<{ helpful_count: number }> =>
  api.data<{ helpful_count: number }>(`reviews/${review}/helpful`, { method: "POST" });

/** POST /api/v1/reviews/{review}/report — flag for moderation. */
export const reportReview = (review: string, input: ReportInput): Promise<void> =>
  api.post<void>(`reviews/${review}/report`, input);
