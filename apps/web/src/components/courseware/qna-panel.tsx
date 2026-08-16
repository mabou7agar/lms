"use client";

import { useState } from "react";
import { CheckCircle2, Clock, Lock, MessageCircleQuestion, ShieldCheck } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { CourseQuestion } from "@/lib/courseware/api";
import {
  useAcceptAnswer,
  useAnswerQuestion,
  useAskQuestion,
  useCourseQuestions,
  useMarkAnswerOfficial,
  useQuestion,
} from "@/lib/courseware/hooks";
import { QueryState } from "@/components/student/query-state";
import { Field } from "@/components/auth/field";
import { FormAlert } from "@/components/auth/form-alert";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";

/**
 * Course and lesson Q&A.
 *
 * Given a `lessonId` it scopes to that lesson and asks on its behalf, which is how the player shows
 * "questions about this lesson" without a second component. Without one it is the course-wide board.
 *
 * A learner can mark a question private, which takes it out of everyone's view but their own and the
 * course team's. That option is offered plainly rather than buried, because the questions people are
 * reluctant to ask in public are usually the ones worth answering.
 */
export function QnaPanel({
  courseId,
  lessonId,
  canManage = false,
}: {
  courseId: string;
  lessonId?: string;
  canManage?: boolean;
}) {
  const { t } = useI18n();
  const [openId, setOpenId] = useState<string | null>(null);
  const questions = useCourseQuestions(courseId, lessonId ? { lesson_id: lessonId } : {});

  return (
    <section className="space-y-4">
      <h3 className="flex items-center gap-2 font-serif text-lg font-semibold">
        <MessageCircleQuestion className="size-5 text-copper" aria-hidden />
        {lessonId ? t("courseware.qna.lessonTitle") : t("courseware.qna.title")}
      </h3>

      <AskForm courseId={courseId} lessonId={lessonId} />

      <QueryState
        query={questions}
        isEmpty={(d) => d.length === 0}
        empty={<p className="text-sm text-muted-foreground">{t("courseware.qna.empty")}</p>}
      >
        {(items) => (
          <ul className="space-y-2">
            {items.map((question) => (
              <li key={question.id}>
                <QuestionRow
                  question={question}
                  open={openId === question.id}
                  onToggle={() => setOpenId(openId === question.id ? null : question.id)}
                  canManage={canManage}
                />
              </li>
            ))}
          </ul>
        )}
      </QueryState>
    </section>
  );
}

function AskForm({ courseId, lessonId }: { courseId: string; lessonId?: string }) {
  const { t } = useI18n();
  const ask = useAskQuestion(courseId);
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");
  const [visibility, setVisibility] = useState<"public" | "private">("public");
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);

  const submit = () => {
    if (title.trim() === "" || body.trim() === "") return;
    setError(null);
    ask.mutate(
      { title: title.trim(), body: body.trim(), lesson_id: lessonId ?? null, visibility },
      {
        onSuccess: () => {
          setTitle("");
          setBody("");
          setSent(true);
        },
        onError: (e) => setError(errorMessage(e, t("common.error"))),
      },
    );
  };

  return (
    <div className="space-y-3 rounded-lg border p-4">
      {sent ? <FormAlert variant="success">{t("courseware.qna.asked")}</FormAlert> : null}
      {error ? <FormAlert>{error}</FormAlert> : null}

      <Field id="qna-title" label={t("courseware.qna.questionTitle")}>
        <Input id="qna-title" value={title} onChange={(e) => setTitle(e.target.value)} maxLength={200} />
      </Field>
      <Field id="qna-body" label={t("courseware.qna.questionBody")}>
        <Textarea id="qna-body" rows={3} value={body} onChange={(e) => setBody(e.target.value)} />
      </Field>

      <div className="flex flex-wrap items-end justify-between gap-3">
        <Field id="qna-visibility" label={t("courseware.qna.visibility")}>
          <Select value={visibility} onValueChange={(v) => setVisibility(v as "public" | "private")}>
            <SelectTrigger id="qna-visibility" className="w-56">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="public">{t("courseware.qna.visibilityPublic")}</SelectItem>
              <SelectItem value="private">{t("courseware.qna.visibilityPrivate")}</SelectItem>
            </SelectContent>
          </Select>
        </Field>

        <Button onClick={submit} disabled={ask.isPending || title.trim() === "" || body.trim() === ""}>
          {ask.isPending ? t("courseware.qna.asking") : t("courseware.qna.ask")}
        </Button>
      </div>
    </div>
  );
}

function QuestionRow({
  question,
  open,
  onToggle,
  canManage,
}: {
  question: CourseQuestion;
  open: boolean;
  onToggle: () => void;
  canManage: boolean;
}) {
  const { t } = useI18n();

  return (
    <div className="rounded-lg border">
      <button
        type="button"
        onClick={onToggle}
        className="flex w-full flex-wrap items-start justify-between gap-3 p-3 text-start"
      >
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
            {question.title}
            {question.is_private ? (
              <Badge variant="secondary">
                <Lock className="me-1 size-3" aria-hidden />
                {t("courseware.qna.private")}
              </Badge>
            ) : null}
            {question.is_resolved ? (
              <Badge variant="success">{t("courseware.qna.statusResolved")}</Badge>
            ) : question.awaiting_response ? (
              <Badge variant="outline">
                <Clock className="me-1 size-3" aria-hidden />
                {t("courseware.qna.awaiting")}
              </Badge>
            ) : null}
          </div>
          <p className="mt-0.5 text-xs text-muted-foreground">
            {question.author?.name ?? "—"} · {t("courseware.qna.answers").replace("{count}", String(question.answers_count))}
          </p>
        </div>
      </button>

      {open ? <Thread questionId={question.id} canManage={canManage} /> : null}
    </div>
  );
}

function Thread({ questionId, canManage }: { questionId: string; canManage: boolean }) {
  const { t } = useI18n();
  const thread = useQuestion(questionId);
  const answer = useAnswerQuestion();
  const accept = useAcceptAnswer();
  const official = useMarkAnswerOfficial();
  const [body, setBody] = useState("");
  const [error, setError] = useState<string | null>(null);

  const submit = () => {
    if (body.trim() === "") return;
    setError(null);
    answer.mutate(
      { questionId, body: body.trim() },
      { onSuccess: () => setBody(""), onError: (e) => setError(errorMessage(e, t("common.error"))) },
    );
  };

  return (
    <div className="space-y-3 border-t p-3">
      <QueryState query={thread} isEmpty={() => false} empty={null}>
        {(data) => (
          <>
            <p className="whitespace-pre-line text-sm text-muted-foreground">{data.body}</p>

            <ul className="space-y-2">
              {data.answers.map((a) => (
                <li key={a.id} className="rounded-md border bg-muted/20 p-3">
                  <div className="mb-1 flex flex-wrap items-center gap-2 text-xs">
                    <span className="font-medium">{a.author?.name ?? "—"}</span>
                    {a.is_instructor ? (
                      <Badge variant="outline">{t("courseware.qna.instructor")}</Badge>
                    ) : null}
                    {a.is_official ? (
                      <Badge variant="default">
                        <ShieldCheck className="me-1 size-3" aria-hidden />
                        {t("courseware.qna.official")}
                      </Badge>
                    ) : null}
                    {a.accepted ? (
                      <Badge variant="success">
                        <CheckCircle2 className="me-1 size-3" aria-hidden />
                        {t("courseware.qna.accepted")}
                      </Badge>
                    ) : null}
                  </div>
                  <p className="whitespace-pre-line text-sm">{a.body}</p>

                  <div className="mt-2 flex flex-wrap gap-2">
                    {!a.accepted ? (
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => accept.mutate({ answerId: a.id, questionId })}
                        disabled={accept.isPending}
                      >
                        {t("courseware.qna.markAccepted")}
                      </Button>
                    ) : null}
                    {canManage && !a.is_official ? (
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => official.mutate({ answerId: a.id, questionId })}
                        disabled={official.isPending}
                      >
                        {t("courseware.qna.markOfficial")}
                      </Button>
                    ) : null}
                  </div>
                </li>
              ))}
            </ul>
          </>
        )}
      </QueryState>

      {error ? <FormAlert>{error}</FormAlert> : null}

      <div className="space-y-2">
        <Field id={`answer-${questionId}`} label={t("courseware.qna.answerLabel")}>
          <Textarea id={`answer-${questionId}`} rows={2} value={body} onChange={(e) => setBody(e.target.value)} />
        </Field>
        <Button size="sm" onClick={submit} disabled={answer.isPending || body.trim() === ""}>
          {answer.isPending ? t("courseware.qna.posting") : t("courseware.qna.post")}
        </Button>
      </div>
    </div>
  );
}
