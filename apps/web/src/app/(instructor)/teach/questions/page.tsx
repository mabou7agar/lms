"use client";

import { useState } from "react";
import { AlertTriangle, Clock, MessageCircleQuestion } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { errorMessage } from "@/lib/api/errors";
import type { CourseQuestion, QnaMetrics } from "@/lib/courseware/api";
import {
  useAnswerQuestion,
  useInstructorQueue,
  useMarkAnswerOfficial,
  useQuestion,
} from "@/lib/courseware/hooks";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { ExpiryBanner } from "@/components/commerce/expiry-banner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { toast } from "@/components/ui/toast";

const FILTERS = ["unanswered", "overdue", "answered", "all"] as const;
type Filter = (typeof FILTERS)[number];

/** Minutes as something readable: "40m", "3h 10m", "2d 4h". */
function formatMinutes(minutes: number | null, t: (k: string) => string): string {
  if (minutes === null) return "—";
  if (minutes < 60) return `${minutes}${t("courseware.inbox.unitMinute")}`;
  if (minutes < 60 * 24) {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m === 0 ? `${h}${t("courseware.inbox.unitHour")}` : `${h}${t("courseware.inbox.unitHour")} ${m}${t("courseware.inbox.unitMinute")}`;
  }
  const d = Math.floor(minutes / (60 * 24));
  const h = Math.floor((minutes % (60 * 24)) / 60);
  return h === 0 ? `${d}${t("courseware.inbox.unitDay")}` : `${d}${t("courseware.inbox.unitDay")} ${h}${t("courseware.inbox.unitHour")}`;
}

/**
 * The instructor's Q&A queue across every course they teach.
 *
 * Ordered oldest-first, which is the opposite of a social feed and the right way round for a queue:
 * the learner who has waited longest is the one to answer next. Overdue questions get their own
 * filter and a banner, because a promise the platform made on the instructor's behalf is not
 * something to discover by scrolling.
 */
export default function InstructorQuestionsPage() {
  const { t } = useI18n();
  const [filter, setFilter] = useState<Filter>("unanswered");
  const queue = useInstructorQueue(filter);

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow={t("courseware.inbox.eyebrow")}
        icon="MessageCircleQuestion"
        title={t("courseware.inbox.title")}
        subtitle={t("courseware.inbox.subtitle")}
      />

      <QueryState query={queue} isEmpty={() => false} empty={null}>
        {(data) => (
          <div className="space-y-6">
            {data.metrics.overdue > 0 ? (
              <ExpiryBanner
                tone="expired"
                title={t("courseware.inbox.overdueBanner")
                  .replace("{count}", String(data.metrics.overdue))
                  .replace("{hours}", String(data.metrics.sla_hours))}
                detail={t("courseware.inbox.overdueHint")}
                action={
                  <Button size="sm" variant="outline" onClick={() => setFilter("overdue")}>
                    {t("courseware.inbox.showOverdue")}
                  </Button>
                }
              />
            ) : null}

            <MetricStrip metrics={data.metrics} />

            <div className="flex flex-wrap gap-2">
              {FILTERS.map((f) => (
                <Button
                  key={f}
                  size="sm"
                  variant={filter === f ? "default" : "outline"}
                  onClick={() => setFilter(f)}
                >
                  {t(`courseware.inbox.filters.${f}`)}
                </Button>
              ))}
            </div>

            {data.questions.length === 0 ? (
              <p className="text-sm text-muted-foreground">{t("courseware.inbox.empty")}</p>
            ) : (
              <ul className="divide-y rounded-lg border">
                {data.questions.map((q) => (
                  <QueueRow key={q.id} question={q} />
                ))}
              </ul>
            )}
          </div>
        )}
      </QueryState>
    </div>
  );
}

/**
 * One queue row, expandable into the full thread with a reply box.
 *
 * The queue used to be read-only: an instructor could see that someone had been waiting two days and
 * had nowhere to answer them, which is the one thing this screen exists for. Expanding in place
 * (rather than routing to a detail page) keeps the queue's ordering and the SLA context on screen
 * while replying, and lets the instructor work straight down the list.
 */
function QueueRow({ question }: { question: CourseQuestion }) {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const [body, setBody] = useState("");

  const thread = useQuestion(open ? question.id : null);
  const answer = useAnswerQuestion();
  const markOfficial = useMarkAnswerOfficial();

  const submit = () => {
    if (!body.trim()) return toast.error(t("courseware.qna.bodyRequired"));
    answer.mutate(
      { questionId: question.id, body: body.trim() },
      {
        onSuccess: () => {
          // Clear the box and let the invalidations refresh the thread, the queue and the metrics.
          setBody("");
          toast.success(t("courseware.qna.answerPosted"));
        },
        onError: (e) => toast.error(errorMessage(e, t("common.error"))),
      },
    );
  };

  return (
    <li className="p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
            {question.title}
            {question.is_private ? <Badge variant="secondary">{t("courseware.qna.private")}</Badge> : null}
            {question.awaiting_response ? (
              <Badge variant="outline">
                <Clock className="me-1 size-3" aria-hidden />
                {t("courseware.qna.awaiting")}
              </Badge>
            ) : null}
          </div>
          <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">{question.body}</p>
          <p className="mt-1 text-xs text-muted-foreground">
            {question.author?.name ?? "—"}
            {question.first_response_minutes !== null
              ? ` · ${t("courseware.inbox.respondedIn")} ${formatMinutes(question.first_response_minutes, t)}`
              : ""}
          </p>
        </div>

        <Button
          size="sm"
          variant="outline"
          onClick={() => setOpen((v) => !v)}
          aria-expanded={open}
          data-testid={`queue-open-${question.id}`}
        >
          {open ? t("common.close") : t("courseware.inbox.openThread")}
        </Button>
      </div>

      {open ? (
        <div className="mt-4 space-y-3 rounded-lg border bg-muted/30 p-3" data-testid={`queue-thread-${question.id}`}>
          <p className="whitespace-pre-line text-sm">{question.body}</p>

          <QueryState query={thread} isEmpty={() => false} empty={null}>
            {(data) => (
              <ul className="space-y-2">
                {data.answers.map((a) => (
                  <li key={a.id} className="rounded-md border bg-background p-3">
                    <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                      <span className="font-medium text-foreground">{a.author?.name ?? "—"}</span>
                      {a.is_instructor ? <Badge variant="secondary">{t("courseware.qna.instructor")}</Badge> : null}
                      {a.is_official ? <Badge variant="success">{t("courseware.qna.official")}</Badge> : null}
                      {a.accepted ? <Badge variant="outline">{t("courseware.qna.accepted")}</Badge> : null}
                    </div>
                    <p className="mt-1.5 whitespace-pre-line text-sm">{a.body}</p>
                    {a.is_instructor && !a.is_official ? (
                      <Button
                        size="sm"
                        variant="ghost"
                        className="mt-2"
                        loading={markOfficial.isPending}
                        onClick={() =>
                          markOfficial.mutate(
                            { answerId: a.id, questionId: question.id },
                            { onError: (e) => toast.error(errorMessage(e, t("common.error"))) },
                          )
                        }
                      >
                        {t("courseware.qna.markOfficial")}
                      </Button>
                    ) : null}
                  </li>
                ))}
              </ul>
            )}
          </QueryState>

          <div className="space-y-2">
            <label htmlFor={`reply-${question.id}`} className="block text-sm font-medium">
              {t("courseware.qna.answerLabel")}
            </label>
            <Textarea
              id={`reply-${question.id}`}
              rows={3}
              value={body}
              onChange={(e) => setBody(e.target.value)}
              placeholder={t("courseware.qna.answerPlaceholder")}
            />
            <Button size="sm" loading={answer.isPending} onClick={submit} data-testid={`queue-reply-${question.id}`}>
              {t("courseware.qna.submitAnswer")}
            </Button>
          </div>
        </div>
      ) : null}
    </li>
  );
}

function MetricStrip({ metrics }: { metrics: QnaMetrics }) {
  const { t } = useI18n();

  const cells = [
    { label: t("courseware.inbox.questions"), value: String(metrics.questions) },
    { label: t("courseware.inbox.unanswered"), value: String(metrics.unanswered) },
    {
      label: t("courseware.inbox.overdue"),
      value: String(metrics.overdue),
      alert: metrics.overdue > 0,
    },
    { label: t("courseware.inbox.responseRate"), value: `${Math.round(metrics.response_rate * 100)}%` },
    // Median sits beside the mean because one answer three weeks late drags an average somewhere
    // nobody recognises.
    { label: t("courseware.inbox.medianResponse"), value: formatMinutes(metrics.median_first_response_minutes, t) },
    { label: t("courseware.inbox.avgResponse"), value: formatMinutes(metrics.avg_first_response_minutes, t) },
  ];

  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
      {cells.map((cell) => (
        <div key={cell.label} className="rounded-lg border p-3">
          <p className="flex items-center gap-1 text-xs text-muted-foreground">
            {cell.alert ? <AlertTriangle className="size-3 text-destructive" aria-hidden /> : null}
            {cell.label}
          </p>
          <p className={cell.alert ? "text-xl font-semibold tabular-nums text-destructive" : "text-xl font-semibold tabular-nums"}>
            {cell.value}
          </p>
        </div>
      ))}
    </div>
  );
}

export const dynamic = "force-dynamic";
