"use client";

import DOMPurify from "isomorphic-dompurify";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";
import { buildBlanksPayload } from "@/lib/assessment/question-model";
import type { AnswerResponse, LearnerOption, LearnerQuestion } from "@/lib/assessment/types";
import { cn } from "@/lib/utils";

/**
 * Renders ONE question as a learner sees it, and reports their response.
 *
 * Shared deliberately between the instructor preview (Slice 4) and the learner player (Slice 5) so
 * the preview cannot drift from what a learner actually experiences — a preview that renders
 * different markup is worse than no preview.
 *
 * SECURITY: this component only ever receives a LearnerQuestion, whose `is_correct` and option
 * `value` fields are ABSENT unless the backend chose to reveal them. It has no access to an answer
 * key and cannot leak one. The instructor preview strips the key before calling it, which is why
 * the preview is safe to build from author data.
 */
export function QuestionPresenter({
  question,
  response,
  onChange,
  reveal = false,
  disabled = false,
}: {
  question: LearnerQuestion;
  response: AnswerResponse | null;
  onChange: (next: AnswerResponse) => void;
  /** Show correctness markers. Only ever true once an attempt is graded AND feedback allows it. */
  reveal?: boolean;
  disabled?: boolean;
}) {
  const { t } = useAuthoringI18n();
  const multi = question.type === "multiple_choice";
  const selected = response?.option_ids ?? [];

  function toggleOption(optionId: string) {
    if (multi) {
      const next = selected.includes(optionId)
        ? selected.filter((id) => id !== optionId)
        : [...selected, optionId];
      onChange({ option_ids: next });

      return;
    }

    onChange({ option_ids: [optionId] });
  }

  return (
    <div className="space-y-4">
      {/* Prompts are author-controlled HTML rendered as markup. Sanitized server-side on write
          (HtmlSanitizer) AND re-sanitized here with DOMPurify before injection (defense in depth),
          matching the lesson/CMS renderers so a single missed server path can't become stored XSS. */}
      <div
        className="prose prose-sm max-w-none dark:prose-invert"
        dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(question.prompt) }}
      />

      {question.hint ? (
        <p className="rounded-md border border-border bg-muted/40 p-2 text-sm text-muted-foreground">
          {question.hint}
        </p>
      ) : null}

      {question.type === "short_answer" ? (
        <Textarea
          rows={3}
          disabled={disabled}
          value={response?.text ?? ""}
          onChange={(e) => onChange({ text: e.target.value })}
          aria-label={t("question.field.prompt")}
        />
      ) : question.type === "fill_in_blank" ? (
        <BlankInputs question={question} response={response} onChange={onChange} disabled={disabled} />
      ) : (
        <fieldset className="space-y-2" disabled={disabled}>
          <legend className="sr-only">{t("options.title")}</legend>
          {question.options.map((option) => (
            <OptionRow
              key={option.id}
              option={option}
              multi={multi}
              checked={selected.includes(option.id)}
              reveal={reveal}
              onToggle={() => toggleOption(option.id)}
            />
          ))}
        </fieldset>
      )}

      {reveal && question.explanation ? (
        <p className="rounded-md border border-border bg-muted/40 p-3 text-sm">{question.explanation}</p>
      ) : null}
    </div>
  );
}

function OptionRow({
  option,
  multi,
  checked,
  reveal,
  onToggle,
}: {
  option: LearnerOption;
  multi: boolean;
  checked: boolean;
  reveal: boolean;
  onToggle: () => void;
}) {
  const { t } = useAuthoringI18n();
  // `undefined` means "not revealed" — never render it as "wrong".
  const correctness = reveal ? option.is_correct : undefined;

  return (
    <label
      className={cn(
        "flex cursor-pointer items-start gap-3 rounded-md border border-border p-3 transition-colors",
        "has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring",
        correctness === true && "border-success bg-success/5",
        correctness === false && checked && "border-destructive bg-destructive/5",
      )}
    >
      {multi ? (
        <Checkbox checked={checked} onChange={onToggle} className="mt-0.5" />
      ) : (
        <input
          type="radio"
          name={`q-${option.id.slice(0, 8)}`}
          checked={checked}
          onChange={onToggle}
          className="mt-0.5 size-4 accent-[var(--color-primary)]"
        />
      )}

      <span className="flex-1 text-sm">{option.label}</span>

      {correctness !== undefined ? (
        // Text, not just colour — colour alone fails anyone who cannot distinguish it.
        <span className={cn("text-xs font-medium", correctness ? "text-success" : "text-destructive")}>
          {correctness ? t("options.correct") : ""}
        </span>
      ) : null}
    </label>
  );
}

function BlankInputs({
  question,
  response,
  onChange,
  disabled,
}: {
  question: LearnerQuestion;
  response: AnswerResponse | null;
  onChange: (next: AnswerResponse) => void;
  disabled: boolean;
}) {
  const { t } = useAuthoringI18n();

  // The learner view carries one option per accepted answer; the number of BLANKS is the number of
  // distinct group_index values, which is what determines how many inputs to show.
  const blanks = [...new Set(question.options.map((o) => o.group_index))].sort((a, b) => a - b);
  const current = response?.blanks ?? {};

  function setBlank(index: number, value: string) {
    const next = new Map<number, string>();
    for (const blank of blanks) {
      next.set(blank, blank === index ? value : (current[String(blank)] ?? ""));
    }
    // buildBlanksPayload guarantees stringified integer keys, matching what the backend narrows.
    onChange({ blanks: buildBlanksPayload(next) });
  }

  return (
    <div className="space-y-2">
      {blanks.map((index) => (
        <Input
          key={index}
          disabled={disabled}
          value={current[String(index)] ?? ""}
          onChange={(e) => setBlank(index, e.target.value)}
          aria-label={t("blank.label", { n: index + 1 })}
          placeholder={t("blank.label", { n: index + 1 })}
        />
      ))}
    </div>
  );
}
