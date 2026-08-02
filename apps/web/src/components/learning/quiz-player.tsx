"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { AlertTriangle, CheckCircle2, Flag, Loader2, RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Progress } from "@/components/ui/progress";
import { Spinner } from "@/components/ui/spinner";
import { QuestionNavigator, type NavigatorItem } from "@/components/assessment/question-navigator";
import { QuestionPresenter } from "@/components/assessment/question-presenter";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";
import { useAttempt, useStartAttempt, useSubmitAttempt } from "@/lib/assessment/hooks";
import { useAnswerAutosave } from "@/lib/assessment/use-answer-autosave";
import type { AnswerResponse, Attempt, AttemptQuestion } from "@/lib/assessment/types";
import { cn } from "@/lib/utils";

/**
 * The learner quiz player.
 *
 * GRADING AUTHORITY: this component never decides whether an answer is right. Correctness comes
 * exclusively from `attempt.questions[].answer.is_correct`, which the backend omits entirely until
 * the learner is entitled to see it. There is no scoring code here, and `previewIsCorrect` — the
 * author-facing approximation used by the instructor preview — is deliberately NOT imported.
 *
 * ATTEMPT AUTHORITY: every answer is written through the attempt API. The component holds no
 * shadow copy of the learner's work beyond the in-flight autosave queue.
 */
export function QuizPlayer({ assessmentId }: { assessmentId: string }) {
  const { t } = useAuthoringI18n();
  const [attemptId, setAttemptId] = useState<string | null>(null);
  const start = useStartAttempt();
  const submit = useSubmitAttempt();
  const { data: attempt, isPending, isError, refetch } = useAttempt(attemptId);

  const open = attempt?.status === "in_progress";
  const autosave = useAnswerAutosave(attemptId, open);

  if (attemptId === null) {
    return (
      <StartPanel
        starting={start.isPending}
        error={start.error instanceof Error ? start.error.message : null}
        onStart={() => start.mutate(assessmentId, { onSuccess: (a) => setAttemptId(a.id) })}
      />
    );
  }

  if (isPending) {
    return (
      <div className="flex min-h-[20rem] items-center justify-center" role="status" aria-live="polite">
        <Spinner />
      </div>
    );
  }

  if (isError || !attempt) {
    return (
      <div className="flex min-h-[20rem] flex-col items-center justify-center gap-3 text-center">
        <AlertTriangle className="size-8 text-destructive" aria-hidden />
        <p className="text-sm text-muted-foreground">{t("builder.loadError")}</p>
        <Button variant="outline" onClick={() => void refetch()}>
          {t("builder.retry")}
        </Button>
      </div>
    );
  }

  // A finished attempt — submitted, graded or expired — is read-only and shows the result screen.
  if (attempt.status !== "in_progress") {
    return <ResultScreen attempt={attempt} />;
  }

  return (
    <ActiveAttempt
      attempt={attempt}
      autosave={autosave}
      submitting={submit.isPending}
      submitError={submit.error instanceof Error ? submit.error.message : null}
      onSubmit={async () => {
        // Flush first: an answer still sitting in the debounce queue would otherwise be lost.
        await autosave.flushNow();
        submit.mutate(attempt.id);
      }}
    />
  );
}

function ActiveAttempt({
  attempt,
  autosave,
  submitting,
  submitError,
  onSubmit,
}: {
  attempt: Attempt;
  autosave: ReturnType<typeof useAnswerAutosave>;
  submitting: boolean;
  submitError: string | null;
  onSubmit: () => Promise<void>;
}) {
  const { t } = useAuthoringI18n();
  const [index, setIndex] = useState(0);
  const [filter, setFilter] = useState<"all" | "unanswered" | "flagged">("all");
  const [flagged, setFlagged] = useState<ReadonlySet<string>>(new Set());
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [expired, setExpired] = useState(false);

  /**
   * Local echo of what the learner has typed, seeded from the server's saved answers. It exists
   * only so the inputs stay responsive between debounced saves — the server remains the record,
   * and this is discarded on reload.
   */
  const [drafts, setDrafts] = useState<Map<string, AnswerResponse>>(() => {
    const seeded = new Map<string, AnswerResponse>();
    for (const item of attempt.questions) {
      if (item.answer?.response) seeded.set(item.question.id, item.answer.response);
    }

    return seeded;
  });

  const questions = attempt.questions;
  const current = questions[index];

  const items = useMemo<NavigatorItem[]>(
    () =>
      questions.map((item) => ({
        id: item.question.id,
        answered: hasResponse(drafts.get(item.question.id)),
        flagged: flagged.has(item.question.id),
      })),
    [questions, drafts, flagged],
  );

  const answeredCount = items.filter((i) => i.answered).length;
  const unansweredCount = questions.length - answeredCount;
  const flaggedCount = flagged.size;

  const secondsLeft = useCountdown(attempt.expires_at, () => setExpired(true));

  // Time is up: submit whatever is saved, exactly as the server would score it.
  useEffect(() => {
    if (expired && !submitting) void onSubmit();
  }, [expired, submitting, onSubmit]);

  const setAnswer = useCallback(
    (questionId: string, response: AnswerResponse) => {
      setDrafts((prev) => new Map(prev).set(questionId, response));
      autosave.queue(questionId, response);
    },
    [autosave],
  );

  const go = useCallback(
    (next: number) => setIndex(Math.max(0, Math.min(next, questions.length - 1))),
    [questions.length],
  );

  // Arrow-key paging. Skipped while focus is in a text field so typing is never hijacked.
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      const target = event.target;
      if (target instanceof HTMLElement && target.closest("input, textarea, [contenteditable]")) return;
      if (event.key === "ArrowRight") go(index + 1);
      if (event.key === "ArrowLeft") go(index - 1);
    }

    window.addEventListener("keydown", onKey);

    return () => window.removeEventListener("keydown", onKey);
  }, [index, go]);

  if (!current) return null;

  return (
    <div className="mx-auto max-w-3xl space-y-5 p-4">
      <header className="space-y-2">
        <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
          <span aria-live="polite">
            {t("preview.progress", { current: index + 1, total: questions.length })}
          </span>
          <SaveIndicator state={autosave.state} error={autosave.error} />
          {secondsLeft !== null ? <Countdown seconds={secondsLeft} /> : null}
        </div>
        <Progress value={(answeredCount / questions.length) * 100} />
        <p className="text-xs text-muted-foreground">
          {t("player.completion", { percent: Math.round((answeredCount / questions.length) * 100) })}
        </p>
      </header>

      <div className="flex flex-wrap gap-2">
        {(["all", "unanswered", "flagged"] as const).map((value) => (
          <Button
            key={value}
            size="sm"
            variant={filter === value ? "secondary" : "ghost"}
            aria-pressed={filter === value}
            onClick={() => setFilter(value)}
          >
            {value === "all"
              ? t("player.filter.all")
              : value === "unanswered"
                ? t("player.filter.unanswered", { n: unansweredCount })
                : t("player.filter.flagged", { n: flaggedCount })}
          </Button>
        ))}
      </div>

      <QuestionNavigator items={items} currentIndex={index} onJump={go} filter={filter} />

      <section aria-label={t("preview.progress", { current: index + 1, total: questions.length })}>
        <QuestionPresenter
          key={current.question.id}
          question={current.question}
          response={drafts.get(current.question.id) ?? null}
          onChange={(next) => setAnswer(current.question.id, next)}
          // Never reveal during an open attempt, whatever the feedback mode says — the backend
          // withholds the key anyway, so there would be nothing to show.
          reveal={false}
        />
      </section>

      {submitError ? (
        <p role="alert" className="text-sm text-destructive">
          {submitError}
        </p>
      ) : null}

      <footer className="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-4">
        <Button
          variant="ghost"
          size="sm"
          aria-pressed={flagged.has(current.question.id)}
          onClick={() =>
            setFlagged((prev) => {
              const next = new Set(prev);
              if (next.has(current.question.id)) next.delete(current.question.id);
              else next.add(current.question.id);

              return next;
            })
          }
        >
          <Flag className="size-4" aria-hidden />
          {flagged.has(current.question.id) ? t("preview.unflag") : t("preview.flag")}
        </Button>

        <div className="flex gap-2">
          <Button variant="outline" size="sm" disabled={index === 0} onClick={() => go(index - 1)}>
            {t("preview.previous")}
          </Button>
          <Button
            variant="outline"
            size="sm"
            disabled={index >= questions.length - 1}
            onClick={() => go(index + 1)}
          >
            {t("preview.next")}
          </Button>
          <Button size="sm" loading={submitting} onClick={() => setConfirmOpen(true)}>
            {t("preview.submit")}
          </Button>
        </div>
      </footer>

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title={t("player.submitConfirm.title")}
        description={t("player.submitConfirm.body", { unanswered: unansweredCount, flagged: flaggedCount })}
        confirmLabel={t("preview.submit")}
        confirmVariant="default"
        loading={submitting}
        onConfirm={async () => {
          await onSubmit();
          setConfirmOpen(false);
        }}
      />
    </div>
  );
}

function ResultScreen({ attempt }: { attempt: Attempt }) {
  const { t } = useAuthoringI18n();
  const result = attempt.result;

  // Correctness is shown only where the BACKEND put it. `is_correct === null` means "not revealed"
  // and must render as nothing, never as "wrong".
  const revealed = attempt.questions.some((item) => item.answer?.is_correct !== null && item.answer !== null);

  return (
    <div className="mx-auto max-w-3xl space-y-6 p-4">
      <div className="relative overflow-hidden rounded-2xl border border-border/70 bg-card p-8 text-center" role="status">
        <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-copper/40 to-transparent" aria-hidden />
        {attempt.status === "expired" ? (
          <p className="mb-3 inline-block rounded-full border border-gold/30 bg-gold/10 px-3 py-1 text-xs font-medium text-foreground">{t("player.expired")}</p>
        ) : null}

        <span
          className={cn(
            "mx-auto grid size-14 place-items-center rounded-2xl",
            result?.passed === true ? "bg-primary/10 text-primary" : result?.passed === false ? "bg-destructive/10 text-destructive" : "bg-copper/10 text-copper",
          )}
        >
          <CheckCircle2 className="size-7" aria-hidden />
        </span>
        <h2 className="mt-4 text-xs font-semibold uppercase tracking-[0.16em] text-muted-foreground">{t("player.result.title")}</h2>

        {result ? (
          <>
            <p className="mt-2 font-serif text-5xl font-bold tabular-nums">
              {result.percentage === null ? "—" : `${result.percentage}%`}
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
              {t("preview.result.score", { score: result.score ?? 0, max: result.max_score ?? 0 })}
            </p>
            {result.passed !== null ? (
              <p className={cn("mt-3 inline-block rounded-full px-3 py-1 text-sm font-semibold", result.passed ? "bg-primary/10 text-primary" : "bg-destructive/10 text-destructive")}>
                {result.passed ? t("player.result.passed") : t("player.result.failed")}
              </p>
            ) : null}
          </>
        ) : (
          <p className="mt-2 text-sm text-muted-foreground">{t("player.result.pending")}</p>
        )}
      </div>

      {revealed ? (
        <section className="space-y-4" aria-label={t("player.result.review")}>
          <h3 className="font-serif text-lg font-semibold">{t("player.result.review")}</h3>
          {attempt.questions.map((item, i) => (
            <ReviewCard key={item.question.id} item={item} number={i + 1} />
          ))}
        </section>
      ) : (
        // feedback_mode = never, or the backend chose not to reveal. Say so rather than showing
        // an empty section the learner will read as a bug.
        <p className="text-sm text-muted-foreground">{t("player.result.noFeedback")}</p>
      )}

      {/* TODO(backend): attempt history needs GET /v1/assessments/{assessment}/attempts before a
          learner can see previous sittings or a "retake" affordance driven by real attempt counts.
          Documented in lib/assessment/api.ts REMAINING_BACKEND. Nothing is faked here. */}
      <p className="text-xs text-muted-foreground">{t("player.history.unavailable")}</p>
    </div>
  );
}

function ReviewCard({ item, number }: { item: AttemptQuestion; number: number }) {
  const { t } = useAuthoringI18n();
  const correct = item.answer?.is_correct ?? null;

  return (
    <article
      className={cn(
        "rounded-lg border p-4",
        correct === true && "border-success",
        correct === false && "border-destructive",
        correct === null && "border-border",
      )}
    >
      <header className="mb-2 flex items-center justify-between gap-2 text-xs">
        <span className="font-medium">{t("question.number", { n: number })}</span>
        {correct !== null ? (
          <span className={cn("font-medium", correct ? "text-success" : "text-destructive")}>
            {correct ? t("player.correct") : t("player.incorrect")}
          </span>
        ) : null}
      </header>

      <QuestionPresenter
        question={item.question}
        response={item.answer?.response ?? null}
        onChange={() => undefined}
        // The key is shown only because the SERVER included it in this payload.
        reveal
        disabled
      />

      {item.answer?.feedback ? (
        <p className="mt-2 rounded-md bg-muted/40 p-2 text-sm">{item.answer.feedback}</p>
      ) : null}
    </article>
  );
}

function StartPanel({
  starting,
  error,
  onStart,
}: {
  starting: boolean;
  error: string | null;
  onStart: () => void;
}) {
  const { t } = useAuthoringI18n();

  return (
    <div className="mx-auto max-w-md p-4">
      <div className="relative overflow-hidden rounded-2xl border border-border/70 bg-card p-8 text-center">
        <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-copper/40 to-transparent" aria-hidden />
        <span className="mx-auto mb-4 grid size-14 place-items-center rounded-2xl bg-copper/10 text-copper">
          <Flag className="size-6" aria-hidden />
        </span>
        <p className="text-muted-foreground">{t("player.startHint")}</p>
        <Button className="mt-6 shine relative overflow-hidden" loading={starting} onClick={onStart}>
          {t("player.start")}
        </Button>
        {error ? (
          // Attempt limits and unpublished assessments both surface here, verbatim from the server.
          <p role="alert" className="mt-4 rounded-lg border border-destructive/30 bg-destructive/[0.06] px-3 py-2 text-sm text-destructive">
            {error}
          </p>
        ) : null}
      </div>
    </div>
  );
}

function SaveIndicator({ state, error }: { state: string; error: string | null }) {
  const { t } = useAuthoringI18n();

  if (state === "error") {
    return (
      <span role="alert" className="flex items-center gap-1 text-xs text-destructive">
        <AlertTriangle className="size-3.5" aria-hidden />
        {error ?? t("player.save.failed")}
      </span>
    );
  }

  return (
    <span className="flex items-center gap-1 text-xs text-muted-foreground" aria-live="polite">
      {state === "saving" ? <Loader2 className="size-3.5 animate-spin motion-reduce:animate-none" aria-hidden /> : null}
      {state === "saving"
        ? t("player.save.saving")
        : state === "saved"
          ? t("player.save.saved")
          : state === "dirty"
            ? t("player.save.unsaved")
            : ""}
    </span>
  );
}

function Countdown({ seconds }: { seconds: number }) {
  const { t } = useAuthoringI18n();
  const minutes = Math.floor(seconds / 60);
  const low = seconds <= 60;

  return (
    <span
      className={cn("flex items-center gap-1 text-xs tabular-nums", low && "font-medium text-destructive")}
      // Announced once a minute rather than every tick — a per-second live region is unusable
      // with a screen reader.
      aria-live={seconds % 60 === 0 ? "polite" : "off"}
    >
      <RefreshCw className="size-3.5" aria-hidden />
      {t("preview.timeRemaining")}: {minutes}:{String(seconds % 60).padStart(2, "0")}
    </span>
  );
}

/** Server-anchored countdown: derived from `expires_at`, so a paused tab cannot gain time. */
function useCountdown(expiresAt: string | null, onExpire: () => void): number | null {
  const [seconds, setSeconds] = useState<number | null>(null);
  const fired = useRef(false);

  useEffect(() => {
    if (!expiresAt) return;

    const deadline = new Date(expiresAt).getTime();

    function tick() {
      const remaining = Math.max(0, Math.round((deadline - Date.now()) / 1000));
      setSeconds(remaining);
      if (remaining === 0 && !fired.current) {
        fired.current = true;
        onExpire();
      }
    }

    tick();
    const id = setInterval(tick, 1000);

    return () => clearInterval(id);
  }, [expiresAt, onExpire]);

  return seconds;
}

function hasResponse(response: AnswerResponse | undefined): boolean {
  if (!response) return false;
  if (response.option_ids?.length) return true;
  if ((response.text ?? "").trim() !== "") return true;

  return Object.values(response.blanks ?? {}).some((value) => value.trim() !== "");
}
