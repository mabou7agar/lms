"use client";

import { useEffect, useMemo, useState } from "react";
import { Flag, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Progress } from "@/components/ui/progress";
import { QuestionNavigator } from "@/components/assessment/question-navigator";
import { QuestionPresenter } from "@/components/assessment/question-presenter";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";
import { previewIsCorrect } from "@/lib/assessment/question-model";
import type { AnswerResponse, Assessment, LearnerQuestion, Question } from "@/lib/assessment/types";
import { cn } from "@/lib/utils";

/**
 * Instructor preview.
 *
 * Runs entirely in local state. It never starts an attempt, never writes an answer, and never
 * touches the attempt API — an instructor trying their own quiz must not consume one of a
 * learner's attempts or appear in results.
 *
 * The key-safety argument: the author already holds the answer key (they wrote it), so the risk is
 * not that THEY see it — it is that a component built to render keys gets reused for learners.
 * That is why the preview strips the key into a LearnerQuestion before rendering, and shares
 * QuestionPresenter with the learner player. The presenter has no way to display a key it was
 * never given, in either caller.
 */
export function AssessmentPreview({
  assessment,
  open,
  onOpenChange,
}: {
  assessment: Assessment;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useAuthoringI18n();
  const questions = useMemo(() => assessment.questions ?? [], [assessment.questions]);

  const [index, setIndex] = useState(0);
  const [responses, setResponses] = useState<Map<string, AnswerResponse>>(new Map());
  const [flagged, setFlagged] = useState<ReadonlySet<string>>(new Set());
  const [submitted, setSubmitted] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [secondsLeft, setSecondsLeft] = useState<number | null>(null);

  const timeLimit = assessment.settings.time_limit_seconds;

  // Reset whenever the dialog opens: a preview is a fresh sitting every time, and carrying state
  // across openings would show stale answers against edited questions. Done during render (React's
  // adjust-state-while-rendering pattern, tracked in state) so it runs exactly once per open —
  // without a setState-in-effect and with no flash of the previous sitting.
  const [wasOpen, setWasOpen] = useState(false);
  if (open && !wasOpen) {
    setWasOpen(true);
    setIndex(0);
    setResponses(new Map());
    setFlagged(new Set());
    setSubmitted(false);
    setSecondsLeft(timeLimit ?? null);
  } else if (!open && wasOpen) {
    setWasOpen(false);
  }

  // Auto-submit exactly when the simulated clock reaches zero — during render, once (guarded by
  // !submitted), mirroring the real attempt expiring server-side.
  if (open && !submitted && secondsLeft !== null && secondsLeft <= 0) {
    setSubmitted(true);
  }

  // Simulated clock: only the external timer lives in the effect (it sets no state synchronously in
  // its body); the zero → submit transition is handled during render above.
  useEffect(() => {
    if (!open || submitted || secondsLeft === null || secondsLeft <= 0) return;
    const timer = setTimeout(() => setSecondsLeft((s) => (s === null ? null : s - 1)), 1000);
    return () => clearTimeout(timer);
  }, [open, submitted, secondsLeft]);

  const current = questions[index];
  const answeredCount = questions.filter((q) => hasResponse(responses.get(q.id))).length;
  const unanswered = questions.length - answeredCount;

  const result = useMemo(() => {
    if (!submitted) return null;

    let score = 0;
    let max = 0;
    for (const question of questions) {
      max += question.points;
      if (previewIsCorrect(question, responses.get(question.id) ?? null)) score += question.points;
    }
    const percentage = max > 0 ? (score / max) * 100 : 0;
    const pass = assessment.settings.passing_score;

    return {
      score: Math.round(score * 100) / 100,
      max: Math.round(max * 100) / 100,
      passed: pass === null ? null : percentage >= pass,
    };
  }, [submitted, questions, responses, assessment.settings.passing_score]);

  function setResponse(questionId: string, next: AnswerResponse) {
    setResponses((prev) => new Map(prev).set(questionId, next));
  }

  function toggleFlag(questionId: string) {
    setFlagged((prev) => {
      const next = new Set(prev);
      if (next.has(questionId)) next.delete(questionId);
      else next.add(questionId);

      return next;
    });
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <span className="rounded-full bg-warning/15 px-2 py-0.5 text-xs font-medium text-warning-foreground">
              {t("preview.badge")}
            </span>
            {t("preview.title", { title: assessment.title })}
          </DialogTitle>
        </DialogHeader>

        <p className="text-xs text-muted-foreground">{t("preview.notice")}</p>

        {questions.length === 0 ? (
          <p className="py-8 text-center text-sm text-muted-foreground">{t("preview.empty")}</p>
        ) : submitted && result ? (
          <ResultPanel
            score={result.score}
            max={result.max}
            passed={result.passed}
            onRestart={() => {
              setSubmitted(false);
              setResponses(new Map());
              setIndex(0);
              setSecondsLeft(timeLimit ?? null);
            }}
            onClose={() => onOpenChange(false)}
          />
        ) : current ? (
          <div className="space-y-4">
            <div className="space-y-2">
              <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span aria-live="polite">
                  {t("preview.progress", { current: index + 1, total: questions.length })}
                </span>
                <span className="text-muted-foreground">{t("preview.answered", { n: answeredCount })}</span>
                {secondsLeft !== null ? (
                  <span className="tabular-nums" aria-live="off">
                    {t("preview.timeRemaining")}: {formatClock(secondsLeft)}
                  </span>
                ) : null}
              </div>
              <Progress value={((index + 1) / questions.length) * 100} />
            </div>

            {/* Shared with the learner player: one palette implementation, so the preview cannot
                drift from what a learner navigates, and windowing for large assessments is
                inherited rather than reimplemented. */}
            <QuestionNavigator
              items={questions.map((question) => ({
                id: question.id,
                answered: hasResponse(responses.get(question.id)),
                flagged: flagged.has(question.id),
              }))}
              currentIndex={index}
              onJump={setIndex}
            />

            <QuestionPresenter
              key={current.id}
              question={toLearnerQuestion(current)}
              response={responses.get(current.id) ?? null}
              onChange={(next) => setResponse(current.id, next)}
            />

            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-4">
              <Button
                variant="ghost"
                size="sm"
                onClick={() => toggleFlag(current.id)}
                aria-pressed={flagged.has(current.id)}
              >
                <Flag className="size-4" aria-hidden />
                {flagged.has(current.id) ? t("preview.unflag") : t("preview.flag")}
              </Button>

              <div className="flex gap-2">
                <Button variant="outline" size="sm" disabled={index === 0} onClick={() => setIndex(index - 1)}>
                  {t("preview.previous")}
                </Button>
                {index < questions.length - 1 ? (
                  <Button size="sm" onClick={() => setIndex(index + 1)}>
                    {t("preview.next")}
                  </Button>
                ) : (
                  <Button size="sm" onClick={() => (unanswered > 0 ? setConfirmOpen(true) : setSubmitted(true))}>
                    {t("preview.submit")}
                  </Button>
                )}
              </div>
            </div>
          </div>
        ) : null}

        <ConfirmDialog
          open={confirmOpen}
          onOpenChange={setConfirmOpen}
          title={t("preview.submitConfirm.title")}
          description={t("preview.submitConfirm.body", { n: unanswered })}
          confirmLabel={t("preview.submitConfirm.confirm")}
          confirmVariant="default"
          onConfirm={() => {
            setSubmitted(true);
            setConfirmOpen(false);
          }}
        />
      </DialogContent>
    </Dialog>
  );
}

function ResultPanel({
  score,
  max,
  passed,
  onRestart,
  onClose,
}: {
  score: number;
  max: number;
  passed: boolean | null;
  onRestart: () => void;
  onClose: () => void;
}) {
  const { t } = useAuthoringI18n();

  return (
    <div className="space-y-4 py-4 text-center" role="status">
      <h3 className="text-sm font-semibold">{t("preview.result.title")}</h3>
      <p className="text-2xl font-semibold tabular-nums">{t("preview.result.score", { score, max })}</p>
      <p className={cn("text-sm font-medium", passed === true && "text-success", passed === false && "text-destructive")}>
        {passed === null
          ? t("preview.result.ungraded")
          : passed
            ? t("preview.result.passed")
            : t("preview.result.failed")}
      </p>
      <p className="text-xs text-muted-foreground">{t("preview.result.note")}</p>

      <div className="flex justify-center gap-2">
        <Button variant="outline" size="sm" onClick={onRestart}>
          {t("preview.restart")}
        </Button>
        <Button size="sm" onClick={onClose}>
          <X className="size-4" aria-hidden />
          {t("preview.close")}
        </Button>
      </div>
    </div>
  );
}

/**
 * Strip the answer key. The preview renders through the learner component, so the key must not be
 * in the object handed to it — `is_correct` and option `value` are simply not carried across.
 */
function toLearnerQuestion(question: Question): LearnerQuestion {
  return {
    id: question.id,
    type: question.type,
    prompt: question.prompt,
    config: question.config,
    hint: question.hint,
    points: question.points,
    explanation: null,
    options: question.options.map((option) => ({
      id: option.id,
      label: option.label,
      group_index: option.group_index,
    })),
  };
}

function hasResponse(response: AnswerResponse | undefined): boolean {
  if (!response) return false;
  if (response.option_ids?.length) return true;
  if ((response.text ?? "").trim() !== "") return true;

  return Object.values(response.blanks ?? {}).some((value) => value.trim() !== "");
}

function formatClock(seconds: number): string {
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;

  return `${mins}:${String(secs).padStart(2, "0")}`;
}
