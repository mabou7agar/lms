"use client";

/**
 * Assignment settings form — the authoring surface for everything on an assignment EXCEPT the
 * rubric (that has its own builder). Fully controlled: it holds no draft of its own, it renders
 * `value` and emits the next flat {@link AssignmentInput} through `onChange`. Validation is the
 * parent's job; per-field messages arrive through `errors`.
 *
 * File-restriction fields appear only for submission types that take a file; the late-penalty field
 * appears only for the `penalised` policy — the backend ignores the others, so surfacing them would
 * invite work that silently vanishes.
 */

import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Switch } from "@/components/ui/switch";
import { FormField } from "@/components/ui/form-field";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useAssignmentsI18n } from "@/lib/assignments/assignments-i18n";
import {
  LATE_POLICIES,
  SUBMISSION_TYPES,
  type AssignmentInput,
  type LatePolicy,
  type SubmissionType,
} from "@/lib/assignments/assignments-api";
import {
  formatFileTypes,
  instructionsToText,
  parseFileTypes,
  requiresFile,
  textToInstructions,
} from "@/lib/assignments/assignments-format";

export interface AssignmentSettingsFormProps {
  value: AssignmentInput;
  onChange: (next: AssignmentInput) => void;
  errors?: Partial<Record<keyof AssignmentInput, string>>;
  disabled?: boolean;
}

const MB = 1024 * 1024;

function toNullableInt(raw: string): number | null {
  const v = raw.trim();
  if (v === "") return null;
  const n = Number.parseInt(v, 10);
  return Number.isFinite(n) ? n : null;
}

function toNullableFloat(raw: string): number | null {
  const v = raw.trim();
  if (v === "") return null;
  const n = Number.parseFloat(v);
  return Number.isFinite(n) ? n : null;
}

/** ISO8601 → the `datetime-local` input value (local wall clock, minute precision). */
function isoToLocalInput(iso: string | null | undefined): string {
  if (!iso) return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** `datetime-local` value → ISO8601 (or null when cleared). */
function localInputToIso(raw: string): string | null {
  if (raw.trim() === "") return null;
  const d = new Date(raw);
  return Number.isNaN(d.getTime()) ? null : d.toISOString();
}

/** Small labelled toggle row (mirrors the assessment settings pattern). */
function SettingToggle({
  label,
  hint,
  checked,
  onChange,
  disabled,
}: {
  label: string;
  hint?: string;
  checked: boolean;
  onChange: (next: boolean) => void;
  disabled?: boolean;
}) {
  return (
    <div className="flex items-start justify-between gap-4 py-2">
      <div className="space-y-0.5">
        <Label className="text-sm font-medium">{label}</Label>
        {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
      </div>
      <Switch checked={checked} onCheckedChange={onChange} disabled={disabled} aria-label={label} />
    </div>
  );
}

export function AssignmentSettingsForm({
  value,
  onChange,
  errors = {},
  disabled = false,
}: AssignmentSettingsFormProps) {
  const { t } = useAssignmentsI18n();

  const patch = (next: Partial<AssignmentInput>) => onChange({ ...value, ...next });
  const type = value.submission_type ?? "text";
  const showFileFields = requiresFile(type);

  return (
    <div className="space-y-8">
      {/* ── Core ─────────────────────────────────────────────────────── */}
      <section className="space-y-4">
        <FormField label={t("field.title")} required error={errors.title}>
          <Input
            value={value.title ?? ""}
            placeholder={t("field.title.placeholder")}
            disabled={disabled}
            onChange={(e) => patch({ title: e.target.value })}
          />
        </FormField>

        <FormField label={t("field.instructions")} hint={t("field.instructions.hint")}>
          {(field) => (
            <Textarea
              id={field.id}
              rows={5}
              disabled={disabled}
              value={instructionsToText(value.instructions)}
              onChange={(e) => patch({ instructions: textToInstructions(e.target.value) })}
            />
          )}
        </FormField>

        <FormField label={t("field.submissionType")} required error={errors.submission_type}>
          {(field) => (
            <Select
              value={type}
              disabled={disabled}
              onValueChange={(v) => patch({ submission_type: v as SubmissionType })}
            >
              <SelectTrigger id={field.id} aria-label={t("field.submissionType")}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {SUBMISSION_TYPES.map((st) => (
                  <SelectItem key={st} value={st}>
                    {t(`submissionType.${st}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </FormField>
      </section>

      {/* ── File restrictions (only when a file is expected) ─────────── */}
      {showFileFields ? (
        <section className="space-y-4" aria-label={t("files.heading")}>
          <h3 className="text-sm font-semibold text-foreground">{t("files.heading")}</h3>

          <FormField label={t("files.allowedTypes")} hint={t("files.allowedTypes.hint")}>
            <Input
              value={formatFileTypes(value.allowed_file_types)}
              placeholder={t("files.allowedTypes.placeholder")}
              disabled={disabled}
              onChange={(e) => {
                const types = parseFileTypes(e.target.value);
                patch({ allowed_file_types: types.length > 0 ? types : null });
              }}
            />
          </FormField>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField label={t("files.maxSize")}>
              <Input
                type="number"
                min={1}
                inputMode="numeric"
                disabled={disabled}
                value={value.max_file_size != null ? String(Math.round(value.max_file_size / MB)) : ""}
                onChange={(e) => {
                  const mb = toNullableInt(e.target.value);
                  patch({ max_file_size: mb != null ? mb * MB : null });
                }}
              />
            </FormField>

            <FormField label={t("files.maxFiles")}>
              <Input
                type="number"
                min={1}
                max={50}
                inputMode="numeric"
                disabled={disabled}
                value={value.max_files != null ? String(value.max_files) : ""}
                onChange={(e) => patch({ max_files: toNullableInt(e.target.value) ?? 1 })}
              />
            </FormField>
          </div>
        </section>
      ) : null}

      {/* ── Attempts ─────────────────────────────────────────────────── */}
      <section className="space-y-4" aria-label={t("attempts.heading")}>
        <h3 className="text-sm font-semibold text-foreground">{t("attempts.heading")}</h3>
        <FormField label={t("attempts.limit")} hint={t("attempts.limit.hint")}>
          <Input
            type="number"
            min={1}
            max={100}
            inputMode="numeric"
            placeholder={t("attempts.unlimited")}
            disabled={disabled}
            value={value.attempt_limit != null ? String(value.attempt_limit) : ""}
            onChange={(e) => patch({ attempt_limit: toNullableInt(e.target.value) })}
          />
        </FormField>
      </section>

      {/* ── Due date + late policy ───────────────────────────────────── */}
      <section className="space-y-4" aria-label={t("due.heading")}>
        <h3 className="text-sm font-semibold text-foreground">{t("due.heading")}</h3>

        <FormField label={t("due.dueAt")} hint={t("due.dueAt.hint")}>
          <Input
            type="datetime-local"
            disabled={disabled}
            value={isoToLocalInput(value.due_at)}
            onChange={(e) => patch({ due_at: localInputToIso(e.target.value) })}
          />
        </FormField>

        <FormField label={t("due.latePolicy")}>
          {(field) => (
            <Select
              value={value.late_policy ?? "blocked"}
              disabled={disabled}
              onValueChange={(v) => patch({ late_policy: v as LatePolicy })}
            >
              <SelectTrigger id={field.id} aria-label={t("due.latePolicy")}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {LATE_POLICIES.map((lp) => (
                  <SelectItem key={lp} value={lp}>
                    {t(`latePolicy.${lp}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </FormField>

        {value.late_policy === "penalised" ? (
          <FormField
            label={t("due.penaltyPercent")}
            hint={t("due.penaltyPercent.hint")}
            error={errors.late_penalty_percent}
          >
            <Input
              type="number"
              min={0}
              max={100}
              inputMode="numeric"
              disabled={disabled}
              value={value.late_penalty_percent != null ? String(value.late_penalty_percent) : ""}
              onChange={(e) => patch({ late_penalty_percent: toNullableInt(e.target.value) })}
            />
          </FormField>
        ) : null}
      </section>

      {/* ── Grading ──────────────────────────────────────────────────── */}
      <section className="space-y-4" aria-label={t("grading.heading")}>
        <h3 className="text-sm font-semibold text-foreground">{t("grading.heading")}</h3>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label={t("grading.maxGrade")} required error={errors.max_grade}>
            <Input
              type="number"
              min={0}
              inputMode="decimal"
              disabled={disabled}
              value={value.max_grade != null ? String(value.max_grade) : ""}
              onChange={(e) => patch({ max_grade: toNullableFloat(e.target.value) ?? 0 })}
            />
          </FormField>

          <FormField
            label={t("grading.passingGrade")}
            hint={t("grading.passingGrade.hint")}
            error={errors.passing_grade}
          >
            <Input
              type="number"
              min={0}
              inputMode="decimal"
              disabled={disabled}
              value={value.passing_grade != null ? String(value.passing_grade) : ""}
              onChange={(e) => patch({ passing_grade: toNullableFloat(e.target.value) })}
            />
          </FormField>
        </div>
      </section>

      {/* ── Completion ───────────────────────────────────────────────── */}
      <section aria-label={t("field.requiredForCompletion")}>
        <SettingToggle
          label={t("field.requiredForCompletion")}
          hint={t("field.requiredForCompletion.hint")}
          checked={value.required_for_completion ?? false}
          onChange={(next) => patch({ required_for_completion: next })}
          disabled={disabled}
        />
      </section>
    </div>
  );
}
