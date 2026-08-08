"use client";

import { useState } from "react";
import { ArrowLeft, CheckCircle2, Pin, MessageCircleQuestion } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useAuth } from "@/lib/auth/auth-context";
import { useCommunityI18n, pluralKey } from "@/lib/community/community-i18n";
import {
  useAcceptAnswer,
  useAnswerQuestion,
  useAskQuestion,
  useQuestion,
  useQuestions,
  useReportAnswer,
  useReportQuestion,
} from "@/lib/community/qna-hooks";
import type { Answer, Question, QuestionSort } from "@/lib/community/qna-api";
import { ReportControl } from "./report-control";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Pagination } from "@/components/ui/pagination";
import { toast } from "@/components/ui/toast";

/** mm:ss for a lesson timestamp anchor. */
function formatTimestamp(seconds: number): string {
  const m = Math.floor(seconds / 60);
  const s = Math.floor(seconds % 60);
  return `${m}:${String(s).padStart(2, "0")}`;
}

interface QnaSectionProps {
  courseId: string;
}

/** Course Q&A: filterable question list, an ask form, and per-question answer threads. */
export function QnaSection({ courseId }: QnaSectionProps) {
  const { t } = useCommunityI18n();
  const [filter, setFilter] = useState<"all" | "unanswered">("all");
  const [page, setPage] = useState(1);
  const [askOpen, setAskOpen] = useState(false);
  const [openQuestionId, setOpenQuestionId] = useState<string | null>(null);

  const sort: QuestionSort = filter === "unanswered" ? "unanswered" : "recent";
  const query = useQuestions(courseId, { sort, page });

  if (openQuestionId) {
    return (
      <div className="space-y-4">
        <Button variant="ghost" size="sm" className="gap-1" onClick={() => setOpenQuestionId(null)}>
          <ArrowLeft className="size-4 rtl:rotate-180" aria-hidden /> {t("qna.back")}
        </Button>
        <QuestionThread courseId={courseId} questionId={openQuestionId} />
      </div>
    );
  }

  const questions = query.data?.data ?? [];
  const lastPage = query.data?.meta.last_page ?? 1;

  return (
    <section aria-labelledby="qna-heading" className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 id="qna-heading" className="text-h2 font-serif">
          {t("qna.title")}
        </h2>
        {!askOpen ? (
          <Button size="sm" onClick={() => setAskOpen(true)}>
            {t("qna.ask")}
          </Button>
        ) : null}
      </div>

      {askOpen ? <AskForm courseId={courseId} onDone={() => setAskOpen(false)} /> : null}

      <div className="flex items-center gap-2">
        {(["all", "unanswered"] as const).map((f) => (
          <Button
            key={f}
            size="sm"
            variant={f === filter ? "secondary" : "ghost"}
            onClick={() => {
              setFilter(f);
              setPage(1);
            }}
          >
            {t(`qna.filter.${f}`)}
          </Button>
        ))}
      </div>

      {query.isPending ? (
        <p className="text-sm text-muted-foreground">{t("common.loading")}</p>
      ) : query.isError ? (
        <div className="space-y-2">
          <p className="text-sm text-muted-foreground">{errorMessage(query.error, t("common.error"))}</p>
          <Button size="sm" variant="outline" onClick={() => query.refetch()}>
            {t("common.retry")}
          </Button>
        </div>
      ) : questions.length === 0 ? (
        <p className="text-sm text-muted-foreground">{filter === "all" ? t("qna.beFirst") : t("qna.empty")}</p>
      ) : (
        <ul className="space-y-3">
          {questions.map((q) => (
            <li key={q.id}>
              <QuestionRow question={q} onOpen={() => setOpenQuestionId(q.id)} />
            </li>
          ))}
        </ul>
      )}

      {lastPage > 1 ? <Pagination page={page} lastPage={lastPage} onPageChange={setPage} /> : null}
    </section>
  );
}

function QuestionRow({ question, onOpen }: { question: Question; onOpen: () => void }) {
  const { t } = useCommunityI18n();
  return (
    <Card className="transition-colors hover:border-primary/30">
      <CardContent className="p-4">
        <button type="button" onClick={onOpen} className="block w-full text-start">
          <div className="mb-1 flex flex-wrap items-center gap-2">
            {question.pinned ? (
              <Badge variant="warning" className="gap-1">
                <Pin className="size-3" aria-hidden /> {t("qna.pinned")}
              </Badge>
            ) : null}
            {question.is_resolved ? (
              <Badge variant="success" className="gap-1">
                <CheckCircle2 className="size-3" aria-hidden /> {t("qna.resolved")}
              </Badge>
            ) : null}
          </div>
          <p className="font-medium leading-snug">{question.title}</p>
          <div className="mt-1.5 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
            <span className="inline-flex items-center gap-1">
              <MessageCircleQuestion className="size-3.5" aria-hidden />
              {t(pluralKey("qna.answers", question.answers_count), { count: question.answers_count })}
            </span>
            {question.author ? <span>{question.author.name}</span> : null}
          </div>
        </button>
      </CardContent>
    </Card>
  );
}

function AskForm({ courseId, onDone }: { courseId: string; onDone: () => void }) {
  const { t } = useCommunityI18n();
  const ask = useAskQuestion(courseId);
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");

  const submit = () => {
    if (!title.trim()) return toast.error(t("qna.titleRequired"));
    if (!body.trim()) return toast.error(t("qna.bodyRequired"));
    ask.mutate(
      { title: title.trim(), body: body.trim() },
      {
        onSuccess: () => {
          toast.success(t("qna.posted"));
          onDone();
        },
        onError: (e) => toast.error(errorMessage(e, t("common.error"))),
      },
    );
  };

  return (
    <Card>
      <CardContent className="space-y-3 p-5">
        <div>
          <label htmlFor="qna-title" className="mb-1.5 block text-sm font-medium">
            {t("qna.titleLabel")}
          </label>
          <Input id="qna-title" value={title} onChange={(e) => setTitle(e.target.value)} placeholder={t("qna.titlePlaceholder")} />
        </div>
        <div>
          <label htmlFor="qna-body" className="mb-1.5 block text-sm font-medium">
            {t("qna.bodyLabel")}
          </label>
          <Textarea id="qna-body" rows={4} value={body} onChange={(e) => setBody(e.target.value)} placeholder={t("qna.bodyPlaceholder")} />
        </div>
        <div className="flex items-center gap-2">
          <Button loading={ask.isPending} onClick={submit}>
            {t("qna.submit")}
          </Button>
          <Button variant="ghost" onClick={onDone}>
            {t("common.cancel")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

function QuestionThread({ courseId, questionId }: { courseId: string; questionId: string }) {
  const { t } = useCommunityI18n();
  const { user } = useAuth();
  const query = useQuestion(questionId);
  const answer = useAnswerQuestion(courseId, questionId);
  const accept = useAcceptAnswer(courseId, questionId);
  const reportQuestion = useReportQuestion();
  const reportAnswer = useReportAnswer();
  const [reply, setReply] = useState("");

  if (query.isPending) return <p className="text-sm text-muted-foreground">{t("common.loading")}</p>;
  if (query.isError) {
    return (
      <div className="space-y-2">
        <p className="text-sm text-muted-foreground">{errorMessage(query.error, t("common.error"))}</p>
        <Button size="sm" variant="outline" onClick={() => query.refetch()}>
          {t("common.retry")}
        </Button>
      </div>
    );
  }

  const question = query.data;
  const isAuthor = Boolean(user && question.author && question.author.id === user.id);

  const postAnswer = () => {
    if (!reply.trim()) return;
    answer.mutate(
      { body: reply.trim() },
      {
        onSuccess: () => {
          setReply("");
          toast.success(t("qna.answerPosted"));
        },
        onError: (e) => toast.error(errorMessage(e, t("common.error"))),
      },
    );
  };

  const onAccept = (a: Answer) =>
    accept.mutate(a.id, {
      onSuccess: () => toast.success(t("qna.accepted.done")),
      onError: (e) => toast.error(errorMessage(e, t("common.error"))),
    });

  return (
    <div className="space-y-4">
      <Card>
        <CardContent className="space-y-2 p-5">
          <div className="flex flex-wrap items-center gap-2">
            {question.pinned ? (
              <Badge variant="warning" className="gap-1">
                <Pin className="size-3" aria-hidden /> {t("qna.pinned")}
              </Badge>
            ) : null}
            {question.is_resolved ? (
              <Badge variant="success" className="gap-1">
                <CheckCircle2 className="size-3" aria-hidden /> {t("qna.resolved")}
              </Badge>
            ) : null}
          </div>
          <h3 className="font-serif text-lg font-semibold">{question.title}</h3>
          <p className="whitespace-pre-line text-sm leading-relaxed text-muted-foreground">{question.body}</p>
          <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
            {question.author ? <span>{question.author.name}</span> : null}
            {question.lesson_timestamp_seconds !== null ? <span>{t("qna.atTimestamp", { time: formatTimestamp(question.lesson_timestamp_seconds) })}</span> : null}
          </div>
          <ReportControl onSubmit={(input) => reportQuestion.mutateAsync({ question: question.id, input })} />
        </CardContent>
      </Card>

      <p className="text-sm font-semibold text-muted-foreground">
        {t(pluralKey("qna.answers", question.answers.length), { count: question.answers.length })}
      </p>

      <ul className="space-y-3">
        {question.answers.map((a) => (
          <li key={a.id}>
            <Card className={a.accepted ? "border-success/40" : undefined}>
              <CardContent className="space-y-2 p-4">
                <div className="flex flex-wrap items-center gap-2">
                  {a.accepted ? (
                    <Badge variant="success" className="gap-1">
                      <CheckCircle2 className="size-3" aria-hidden /> {t("qna.accepted")}
                    </Badge>
                  ) : null}
                  {a.is_instructor ? <Badge variant="info">{t("qna.instructor")}</Badge> : null}
                </div>
                <p className="whitespace-pre-line text-sm leading-relaxed text-muted-foreground">{a.body}</p>
                <div className="flex flex-wrap items-center gap-2">
                  {a.author ? <span className="text-xs text-muted-foreground">{a.author.name}</span> : null}
                  {isAuthor && !a.accepted ? (
                    <Button variant="ghost" size="sm" className="gap-1 text-xs text-success" loading={accept.isPending} onClick={() => onAccept(a)}>
                      <CheckCircle2 className="size-3.5" aria-hidden /> {t("qna.accept")}
                    </Button>
                  ) : null}
                  <ReportControl onSubmit={(input) => reportAnswer.mutateAsync({ answer: a.id, input })} />
                </div>
              </CardContent>
            </Card>
          </li>
        ))}
      </ul>

      <Card>
        <CardContent className="space-y-3 p-4">
          <label htmlFor="qna-answer" className="block text-sm font-medium">
            {t("qna.answerLabel")}
          </label>
          <Textarea id="qna-answer" rows={3} value={reply} onChange={(e) => setReply(e.target.value)} placeholder={t("qna.answerPlaceholder")} />
          <Button size="sm" loading={answer.isPending} disabled={!reply.trim()} onClick={postAnswer}>
            {t("qna.postAnswer")}
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}
