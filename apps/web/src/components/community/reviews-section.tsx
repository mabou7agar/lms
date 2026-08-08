"use client";

import { useState } from "react";
import { errorMessage } from "@/lib/api/errors";
import { useCommunityI18n, pluralKey } from "@/lib/community/community-i18n";
import {
  useCreateReview,
  useDeleteReview,
  useMarkReviewHelpful,
  useReportReview,
  useReviews,
  useUpdateReview,
} from "@/lib/community/reviews-hooks";
import type { Review, ReviewSort } from "@/lib/community/reviews-api";
import { StarRating } from "./star-rating";
import { ReportControl } from "./report-control";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Textarea } from "@/components/ui/textarea";
import { Pagination } from "@/components/ui/pagination";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { toast } from "@/components/ui/toast";
import { ThumbsUp, CheckCircle2, Pencil, Trash2 } from "lucide-react";

interface ReviewsSectionProps {
  courseId: string;
  /** Whether the viewer may write a review (enrolled learner). */
  canReview?: boolean;
  /** Whether the viewer is signed in (drives the sign-in hint when they can't review). */
  isAuthenticated?: boolean;
}

const SORTS: ReviewSort[] = ["recent", "helpful", "rating"];

/**
 * Public reviews surface: rating summary (average + star distribution), a paginated list with
 * helpful-vote + report actions and any instructor response, plus a write/edit form gated to an
 * enrolled learner. "Own review" is tracked for the session (the API exposes no author on a review),
 * enabling edit/delete of a just-written review.
 */
export function ReviewsSection({ courseId, canReview = false, isAuthenticated = false }: ReviewsSectionProps) {
  const { t } = useCommunityI18n();
  const [sort, setSort] = useState<ReviewSort>("recent");
  const [page, setPage] = useState(1);
  const [myReview, setMyReview] = useState<Review | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);

  const query = useReviews(courseId, sort, page);
  const remove = useDeleteReview(courseId);

  const aggregate = query.data?.meta.aggregate;
  const pagination = query.data?.meta.pagination;
  const reviews = (query.data?.data ?? []).filter((r) => r.id !== myReview?.id);

  const onDeleteOwn = () => {
    if (!myReview) return;
    remove.mutate(myReview.id, {
      onSuccess: () => {
        setMyReview(null);
        setConfirmDelete(false);
        toast.success(t("reviews.deleted"));
      },
      onError: (e) => toast.error(errorMessage(e, t("common.error"))),
    });
  };

  return (
    <section aria-labelledby="reviews-heading" className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <h2 id="reviews-heading" className="text-h2 font-serif">
          {t("reviews.title")}
        </h2>
        {canReview && !formOpen && !myReview ? (
          <Button size="sm" onClick={() => setFormOpen(true)}>
            {t("reviews.write")}
          </Button>
        ) : null}
      </div>

      {/* Aggregate */}
      {aggregate && aggregate.reviews_count > 0 ? (
        <Card>
          <CardContent className="grid gap-6 p-6 sm:grid-cols-[auto_1fr] sm:items-center">
            <div className="text-center">
              <div className="text-4xl font-serif font-semibold tabular-nums">{aggregate.average_rating.toFixed(1)}</div>
              <StarRating value={aggregate.average_rating} className="mt-1 justify-center" />
              <p className="mt-1 text-xs text-muted-foreground">
                {t(pluralKey("reviews.count", aggregate.reviews_count), { count: aggregate.reviews_count })}
              </p>
            </div>
            <div className="space-y-1.5" aria-label={t("reviews.distribution")}>
              {[5, 4, 3, 2, 1].map((star) => {
                const count = aggregate.distribution[String(star) as "1" | "2" | "3" | "4" | "5"];
                const pct = aggregate.reviews_count > 0 ? (count / aggregate.reviews_count) * 100 : 0;
                return (
                  <div key={star} className="flex items-center gap-2 text-xs" aria-label={t("reviews.starsAria", { stars: star })}>
                    <span className="w-6 shrink-0 tabular-nums text-muted-foreground">{star}★</span>
                    <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                      <div className="h-full rounded-full bg-warning" style={{ inlineSize: `${pct}%` }} aria-hidden />
                    </div>
                    <span className="w-8 shrink-0 text-end tabular-nums text-muted-foreground">{count}</span>
                  </div>
                );
              })}
            </div>
          </CardContent>
        </Card>
      ) : null}

      {/* Write / edit form */}
      {canReview && (formOpen || myReview) ? (
        <ReviewForm
          courseId={courseId}
          existing={myReview}
          onSaved={(review) => {
            setMyReview(review);
            setFormOpen(false);
          }}
          onCancel={() => setFormOpen(false)}
        />
      ) : null}
      {!canReview && isAuthenticated === false ? (
        <p className="text-sm text-muted-foreground">{t("reviews.signInToReview")}</p>
      ) : null}

      {/* Own review with edit/delete */}
      {myReview ? (
        <Card className="border-copper/30">
          <CardContent className="space-y-2 p-5">
            <div className="flex items-center justify-between gap-2">
              <div className="flex items-center gap-2">
                <StarRating value={myReview.rating} size="sm" />
                <Badge variant="secondary">{t("reviews.you")}</Badge>
              </div>
              <div className="flex items-center gap-1">
                <Button variant="ghost" size="sm" className="gap-1" onClick={() => setFormOpen(true)}>
                  <Pencil className="size-3.5" aria-hidden /> {t("common.edit")}
                </Button>
                <Button variant="ghost" size="sm" className="gap-1 text-destructive" loading={remove.isPending} onClick={() => setConfirmDelete(true)}>
                  <Trash2 className="size-3.5" aria-hidden /> {t("common.delete")}
                </Button>
              </div>
            </div>
            {myReview.body ? <p className="whitespace-pre-line text-sm leading-relaxed text-muted-foreground">{myReview.body}</p> : null}
          </CardContent>
        </Card>
      ) : null}

      {/* Sort */}
      {aggregate && aggregate.reviews_count > 0 ? (
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{t("reviews.sort")}:</span>
          {SORTS.map((s) => (
            <Button
              key={s}
              size="sm"
              variant={s === sort ? "secondary" : "ghost"}
              onClick={() => {
                setSort(s);
                setPage(1);
              }}
            >
              {t(`reviews.sort.${s}`)}
            </Button>
          ))}
        </div>
      ) : null}

      {/* List */}
      {query.isPending ? (
        <p className="text-sm text-muted-foreground">{t("common.loading")}</p>
      ) : query.isError ? (
        <div className="space-y-2">
          <p className="text-sm text-muted-foreground">{errorMessage(query.error, t("common.error"))}</p>
          <Button size="sm" variant="outline" onClick={() => query.refetch()}>
            {t("common.retry")}
          </Button>
        </div>
      ) : reviews.length === 0 && !myReview ? (
        <p className="text-sm text-muted-foreground">{aggregate && aggregate.reviews_count > 0 ? t("reviews.empty") : t("reviews.beFirst")}</p>
      ) : (
        <ul className="space-y-4">
          {reviews.map((review) => (
            <li key={review.id}>
              <ReviewItem courseId={courseId} review={review} />
            </li>
          ))}
        </ul>
      )}

      {pagination && pagination.last_page > 1 ? (
        <Pagination page={pagination.current_page} lastPage={pagination.last_page} onPageChange={setPage} />
      ) : null}

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        description={t("reviews.deleteConfirm")}
        confirmLabel={t("common.delete")}
        loading={remove.isPending}
        onConfirm={onDeleteOwn}
      />
    </section>
  );
}

function ReviewForm({
  courseId,
  existing,
  onSaved,
  onCancel,
}: {
  courseId: string;
  existing: Review | null;
  onSaved: (review: Review) => void;
  onCancel: () => void;
}) {
  const { t } = useCommunityI18n();
  const create = useCreateReview(courseId);
  const update = useUpdateReview(courseId);
  const [rating, setRating] = useState(existing?.rating ?? 0);
  const [body, setBody] = useState(existing?.body ?? "");
  const pending = create.isPending || update.isPending;

  const submit = () => {
    if (rating < 1) {
      toast.error(t("reviews.ratingRequired"));
      return;
    }
    const onSuccess = (review: Review) => {
      onSaved(review);
      toast.success(existing ? t("reviews.updated") : t("reviews.saved"));
    };
    const onError = (e: unknown) => toast.error(errorMessage(e, t("common.error")));
    if (existing) {
      update.mutate({ review: existing.id, input: { rating, body: body.trim() || null } }, { onSuccess, onError });
    } else {
      create.mutate({ rating, body: body.trim() || null }, { onSuccess, onError });
    }
  };

  return (
    <Card>
      <CardContent className="space-y-4 p-5">
        <p className="font-serif text-lg font-semibold">{existing ? t("reviews.edit") : t("reviews.write")}</p>
        <div>
          <span className="mb-1.5 block text-sm font-medium">{t("reviews.yourRating")}</span>
          <StarRating value={rating} onChange={setRating} size="lg" />
        </div>
        <div>
          <label htmlFor="review-body" className="mb-1.5 block text-sm font-medium">
            {t("reviews.bodyLabel")}
          </label>
          <Textarea id="review-body" rows={4} value={body} onChange={(e) => setBody(e.target.value)} placeholder={t("reviews.bodyPlaceholder")} />
        </div>
        <div className="flex items-center gap-2">
          <Button loading={pending} onClick={submit}>
            {existing ? t("reviews.update") : t("reviews.submit")}
          </Button>
          <Button variant="ghost" onClick={onCancel}>
            {t("common.cancel")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

function ReviewItem({ courseId, review }: { courseId: string; review: Review }) {
  const { t } = useCommunityI18n();
  const helpful = useMarkReviewHelpful(courseId);
  const report = useReportReview();

  return (
    <Card>
      <CardContent className="space-y-3 p-5">
        <div className="flex flex-wrap items-center gap-2">
          <StarRating value={review.rating} size="sm" />
          {review.verified ? (
            <Badge variant="success" className="gap-1">
              <CheckCircle2 className="size-3" aria-hidden /> {t("reviews.verified")}
            </Badge>
          ) : null}
        </div>
        {review.body ? <p className="whitespace-pre-line text-sm leading-relaxed text-muted-foreground">{review.body}</p> : null}

        {review.instructor_response ? (
          <div className="rounded-lg border-s-2 border-copper bg-surface/40 p-3">
            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-copper">{t("reviews.instructorResponse")}</p>
            <p className="whitespace-pre-line text-sm text-muted-foreground">{review.instructor_response}</p>
          </div>
        ) : null}

        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="ghost"
            size="sm"
            className="h-auto gap-1.5 px-2 py-1 text-xs text-muted-foreground hover:text-foreground"
            loading={helpful.isPending}
            onClick={() => helpful.mutate(review.id)}
          >
            <ThumbsUp className="size-3.5" aria-hidden />
            {review.helpful_count > 0 ? t("reviews.helpfulCount", { count: review.helpful_count }) : t("reviews.helpful")}
          </Button>
          <ReportControl onSubmit={(input) => report.mutateAsync({ review: review.id, input })} />
        </div>
      </CardContent>
    </Card>
  );
}
