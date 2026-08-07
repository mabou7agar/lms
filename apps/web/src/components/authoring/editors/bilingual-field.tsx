"use client";

import { Badge } from "@/components/ui/badge";
import { FormField } from "@/components/ui/form-field";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";
import type { LocaleCode, LocalizedText } from "@/lib/authoring/types";

/**
 * A translatable field edited in both English and Arabic. The Arabic control renders `dir="rtl"`
 * (and `lang="ar"`) so the script and caret behave correctly; the English control stays LTR. A
 * completeness badge shows whether both languages are filled, and the Arabic field hints that empty
 * Arabic falls back to English for learners. There is no raw-JSON editing — the map is never exposed.
 */
export function BilingualField({
  label,
  value,
  onChange,
  onBlur,
  multiline = false,
  rows = 4,
  required = false,
  error,
  hint,
}: {
  label: string;
  value: LocalizedText;
  onChange: (lang: LocaleCode, text: string) => void;
  onBlur?: () => void;
  multiline?: boolean;
  rows?: number;
  required?: boolean;
  /** Validation error, attached to the English (primary) control. */
  error?: string;
  hint?: string;
}) {
  const { t } = useAuthoringI18n();
  const complete = value.en.trim().length > 0 && value.ar.trim().length > 0;
  const arEmpty = value.ar.trim().length === 0;

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-medium">
          {label}
          {required ? (
            <span aria-hidden className="ms-0.5 text-destructive">
              *
            </span>
          ) : null}
        </span>
        <Badge variant="outline" className="text-[0.65rem]">
          {complete ? t("i18n.badge.complete") : t("i18n.badge.enOnly")}
        </Badge>
      </div>

      <FormField label={`${label} · ${t("i18n.english")}`} error={error} hint={hint}>
        {multiline ? (
          <Textarea rows={rows} lang="en" dir="ltr" value={value.en} onChange={(e) => onChange("en", e.target.value)} onBlur={onBlur} />
        ) : (
          <Input lang="en" dir="ltr" value={value.en} onChange={(e) => onChange("en", e.target.value)} onBlur={onBlur} />
        )}
      </FormField>

      <FormField label={`${label} · ${t("i18n.arabic")}`} hint={arEmpty ? t("i18n.fallbackToEn") : undefined}>
        {multiline ? (
          <Textarea rows={rows} lang="ar" dir="rtl" value={value.ar} onChange={(e) => onChange("ar", e.target.value)} onBlur={onBlur} />
        ) : (
          <Input lang="ar" dir="rtl" value={value.ar} onChange={(e) => onChange("ar", e.target.value)} onBlur={onBlur} />
        )}
      </FormField>
    </div>
  );
}
