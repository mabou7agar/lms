"use client";

/**
 * Rubric builder — edits criteria, their levels and the points each level is worth, plus ordering.
 * Fully controlled: renders `value` ({@link RubricInput}) and emits the next value through
 * `onChange`. The displayed total is the DETERMINISTIC client mirror of the server's computation
 * (`rubricTotalPoints`: sum over criteria of their max level points) so the author sees exactly what
 * `PUT …/rubric` will persist. No ids/positions are surfaced — the server assigns them by array
 * order, and points totals are recomputed there from the levels.
 */

import { Plus, Trash2, ChevronUp, ChevronDown } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Badge } from "@/components/ui/badge";
import { FormField } from "@/components/ui/form-field";
import { useAssignmentsI18n } from "@/lib/assignments/assignments-i18n";
import type {
  RubricCriterionInput,
  RubricInput,
  RubricLevelInput,
} from "@/lib/assignments/assignments-api";
import {
  criterionMaxPoints,
  emptyCriterion,
  emptyLevel,
  formatPoints,
  moveItem,
  rubricTotalPoints,
} from "@/lib/assignments/assignments-format";

export interface RubricBuilderProps {
  value: RubricInput;
  onChange: (next: RubricInput) => void;
  error?: string;
  disabled?: boolean;
}

function toPoints(raw: string): number {
  const n = Number.parseFloat(raw);
  return Number.isFinite(n) ? n : 0;
}

export function RubricBuilder({ value, onChange, error, disabled = false }: RubricBuilderProps) {
  const { t } = useAssignmentsI18n();
  const criteria = value.criteria ?? [];
  const total = rubricTotalPoints(criteria);

  const setCriteria = (next: RubricCriterionInput[]) => onChange({ ...value, criteria: next });

  const patchCriterion = (index: number, next: Partial<RubricCriterionInput>) =>
    setCriteria(criteria.map((c, i) => (i === index ? { ...c, ...next } : c)));

  const patchLevel = (ci: number, li: number, next: Partial<RubricLevelInput>) =>
    patchCriterion(ci, {
      levels: criteria[ci].levels.map((l, i) => (i === li ? { ...l, ...next } : l)),
    });

  return (
    <div className="space-y-6">
      {/* Title + live total */}
      <div className="flex flex-wrap items-end justify-between gap-4">
        <FormField label={t("rubric.title")} className="min-w-64 flex-1">
          <Input
            value={value.title ?? ""}
            placeholder={t("rubric.title.placeholder")}
            disabled={disabled}
            onChange={(e) => onChange({ ...value, title: e.target.value === "" ? null : e.target.value })}
          />
        </FormField>
        <div className="text-end">
          <p className="text-xs text-muted-foreground">{t("rubric.total")}</p>
          <p data-testid="rubric-total" className="text-lg font-semibold tabular-nums text-foreground">
            {t("rubric.total.value", { points: formatPoints(total) })}
          </p>
        </div>
      </div>

      {error ? (
        <p role="alert" className="text-xs font-medium text-destructive">
          {error}
        </p>
      ) : null}

      {/* Criteria */}
      {criteria.length === 0 ? (
        <p className="rounded-md border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
          {t("rubric.empty")}
        </p>
      ) : (
        <ul className="space-y-4">
          {criteria.map((criterion, ci) => (
            <li
              key={ci}
              className="space-y-4 rounded-lg border border-border p-4"
              aria-label={t("rubric.criterion", { n: ci + 1 })}
            >
              <div className="flex items-start justify-between gap-2">
                <span className="text-xs font-medium text-muted-foreground">
                  {t("rubric.criterion", { n: ci + 1 })}
                </span>
                <div className="flex items-center gap-1">
                  <Badge variant="secondary" className="tabular-nums">
                    {t("rubric.criterionPoints", { points: formatPoints(criterionMaxPoints(criterion.levels)) })}
                  </Badge>
                  <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    disabled={disabled || ci === 0}
                    aria-label={t("rubric.moveUp")}
                    onClick={() => setCriteria(moveItem(criteria, ci, ci - 1))}
                  >
                    <ChevronUp className="size-4" aria-hidden />
                  </Button>
                  <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    disabled={disabled || ci === criteria.length - 1}
                    aria-label={t("rubric.moveDown")}
                    onClick={() => setCriteria(moveItem(criteria, ci, ci + 1))}
                  >
                    <ChevronDown className="size-4" aria-hidden />
                  </Button>
                  <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    disabled={disabled}
                    aria-label={t("rubric.removeCriterion")}
                    onClick={() => setCriteria(criteria.filter((_, i) => i !== ci))}
                  >
                    <Trash2 className="size-4 text-destructive" aria-hidden />
                  </Button>
                </div>
              </div>

              <FormField label={t("rubric.criterionTitle")}>
                <Input
                  value={criterion.title}
                  placeholder={t("rubric.criterionTitle.placeholder")}
                  disabled={disabled}
                  onChange={(e) => patchCriterion(ci, { title: e.target.value })}
                />
              </FormField>

              <FormField label={t("rubric.criterionDescription")}>
                {(field) => (
                  <Textarea
                    id={field.id}
                    rows={2}
                    disabled={disabled}
                    value={criterion.description ?? ""}
                    onChange={(e) =>
                      patchCriterion(ci, { description: e.target.value === "" ? null : e.target.value })
                    }
                  />
                )}
              </FormField>

              {/* Levels */}
              <div className="space-y-3">
                <p className="text-xs font-medium text-muted-foreground">{t("rubric.levels")}</p>
                <ul className="space-y-2">
                  {criterion.levels.map((level, li) => (
                    <li key={li} className="flex items-end gap-2">
                      <FormField label={t("rubric.levelTitle")} className="flex-1" hideLabel>
                        <Input
                          value={level.title}
                          placeholder={t("rubric.levelTitle.placeholder")}
                          disabled={disabled}
                          onChange={(e) => patchLevel(ci, li, { title: e.target.value })}
                        />
                      </FormField>
                      <FormField label={t("rubric.levelPoints")} className="w-24" hideLabel>
                        <Input
                          type="number"
                          min={0}
                          inputMode="decimal"
                          disabled={disabled}
                          value={String(level.points)}
                          onChange={(e) => patchLevel(ci, li, { points: toPoints(e.target.value) })}
                        />
                      </FormField>
                      <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        disabled={disabled || criterion.levels.length === 1}
                        aria-label={t("rubric.removeLevel")}
                        onClick={() =>
                          patchCriterion(ci, { levels: criterion.levels.filter((_, i) => i !== li) })
                        }
                      >
                        <Trash2 className="size-4 text-destructive" aria-hidden />
                      </Button>
                    </li>
                  ))}
                </ul>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={disabled}
                  onClick={() => patchCriterion(ci, { levels: [...criterion.levels, emptyLevel()] })}
                >
                  <Plus className="size-4" aria-hidden />
                  {t("rubric.addLevel")}
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}

      <Button
        type="button"
        variant="outline"
        disabled={disabled}
        onClick={() => setCriteria([...criteria, emptyCriterion()])}
      >
        <Plus className="size-4" aria-hidden />
        {t("rubric.addCriterion")}
      </Button>
    </div>
  );
}
