"use client";

import { useCallback } from "react";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";
import { useBuilder } from "@/lib/authoring/builder-store";
import type { LocalizedText } from "@/lib/authoring/types";
import { useLocalizedAutosave } from "../field-autosave";
import { StatusBadge } from "../status-badge";
import { BilingualField } from "./bilingual-field";

export function SectionEditor({ sectionId }: { sectionId: string }) {
  const { t } = useAuthoringI18n();
  const builder = useBuilder();
  const section = builder.curriculum?.sections.find((s) => s.id === sectionId);

  const commitTitle = useCallback((v: LocalizedText) => builder.setSectionTitle(sectionId, v), [builder, sectionId]);
  const commitSummary = useCallback((v: LocalizedText) => builder.setSectionSummary(sectionId, v), [builder, sectionId]);

  const title = useLocalizedAutosave(section?.title_i18n ?? { en: "", ar: "" }, commitTitle);
  const summary = useLocalizedAutosave(section?.summary_i18n ?? { en: "", ar: "" }, commitSummary);

  if (!section) return null;

  return (
    <div className="mx-auto max-w-2xl space-y-6 p-6">
      <div className="flex items-center justify-between">
        <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{t("node.section")}</span>
        <StatusBadge state={section.publish_state} />
      </div>

      <BilingualField
        label={t("editor.section.titleLabel")}
        required
        value={title.value}
        onChange={title.setLang}
        onBlur={title.flush}
        error={title.value.en.trim() ? undefined : t("validation.sectionTitle")}
      />

      <BilingualField
        label={t("editor.section.summaryLabel")}
        multiline
        value={summary.value}
        onChange={summary.setLang}
        onBlur={summary.flush}
        hint={t("editor.section.summaryHint")}
      />
    </div>
  );
}
