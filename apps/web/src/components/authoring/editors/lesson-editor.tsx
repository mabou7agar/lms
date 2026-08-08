"use client";

import { useCallback, useEffect, useState } from "react";
import dynamic from "next/dynamic";
import { Sparkles } from "lucide-react";
import { FormField } from "@/components/ui/form-field";
import { Textarea } from "@/components/ui/textarea";
import { readString, withValue } from "@/lib/authoring/block-content";
import { blockDef } from "@/lib/authoring/block-registry";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";
import { useBuilder } from "@/lib/authoring/builder-store";
import type { BlockContent, LocalizedText } from "@/lib/authoring/types";
import { QuizLessonPanel } from "../assessment/quiz-lesson-panel";
import { LessonBlocksPanel } from "../blocks/lesson-blocks-panel";
import { BlockIcon } from "../block-icon";
import { useLocalizedAutosave } from "../field-autosave";
import { StatusBadge } from "../status-badge";
import { BilingualField } from "./bilingual-field";
import { ExternalLinkEditor } from "./external-link-editor";
import { MediaEditor } from "./media-editor";

// Lazy-load the rich-text editor. TipTap (ProseMirror + its extensions) is heavy and only used on
// the course-builder edit surface, so splitting it into its own async chunk keeps it out of the
// route's initial JS — shrinking First Load for /teach/courses/[public_id]/edit. ssr:false is safe
// because the editor is a client-only 'use client' component.
const RichTextEditor = dynamic(() => import("./rich-text-editor").then((m) => m.RichTextEditor), {
  ssr: false,
  loading: () => <div className="min-h-[12rem] animate-pulse rounded-md border bg-muted/40" />,
});

/**
 * Center-pane lesson authoring surface.
 *
 * Content edits flow through the SAME debounced `setBlockContent` path the builder already used, so
 * autosave, undo/redo and the save indicator are unchanged. Only the controls differ per lesson
 * type. Media lives outside `content` (its own `lesson_media` row) and therefore has its own
 * explicit save — see `MediaEditor`.
 */
export function LessonEditor({ sectionId, blockId }: { sectionId: string; blockId: string }) {
  const { t } = useAuthoringI18n();
  const builder = useBuilder();
  const block = builder.curriculum?.sections.find((s) => s.id === sectionId)?.blocks.find((b) => b.id === blockId);

  const commitTitle = useCallback(
    (v: LocalizedText) => builder.setBlockTitle(sectionId, blockId, v),
    [builder, sectionId, blockId],
  );
  const title = useLocalizedAutosave(block?.title_i18n ?? { en: "", ar: "" }, commitTitle);

  const [content, setContent] = useState<BlockContent>(block?.content ?? {});
  const contentJson = JSON.stringify(content);
  useEffect(() => {
    if (!block) return;
    if (contentJson === JSON.stringify(block.content)) return;
    const id = setTimeout(
      () => void builder.setBlockContent(sectionId, blockId, JSON.parse(contentJson) as BlockContent),
      700,
    );
    return () => clearTimeout(id);
  }, [contentJson, block, builder, sectionId, blockId]);

  if (!block) return null;
  const def = blockDef(block.kind);
  // Not wrapped in FormField: the rich-text surface is a contenteditable region, not a labellable
  // control, so it carries its own aria-label instead of a dangling <label for>.
  const richText = (
    <div className="space-y-1.5">
      <p className="text-sm font-medium">{t("field.article.body")}</p>
      <RichTextEditor
        // Remount on lesson switch so the ProseMirror document is rebuilt from the new value.
        key={block.id}
        value={readString(content, "html")}
        onChange={(html) => setContent((prev) => withValue(prev, "html", html))}
        ariaLabel={t("field.article.body")}
      />
      <p className="text-xs text-muted-foreground">{t("field.article.hint")}</p>
    </div>
  );

  return (
    <div className="mx-auto max-w-2xl space-y-6 p-6">
      <div className="flex items-center justify-between">
        <span className="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
          <BlockIcon kind={block.kind} />
          {t(def.labelKey)}
        </span>
        <StatusBadge state={block.publish_state} />
      </div>

      <BilingualField
        label={t("editor.block.titleLabel")}
        required
        value={title.value}
        onChange={title.setLang}
        onBlur={title.flush}
        error={title.value.en.trim() ? undefined : t("validation.blockTitle")}
      />


      {block.kind === "quiz" ? (
        // A quiz lesson's payload is an attached Assessment, not `content` or media — so it gets
        // the assessment builder rather than a content editor. Everything above (title, status)
        // stays identical to every other lesson type.
        <QuizLessonPanel block={block} />
      ) : !def.supported ? (
        <UnsupportedPanel kindLabel={t(def.labelKey)} />
      ) : def.usesMedia ? (
        <>
          <MediaEditor key={block.id} sectionId={sectionId} block={block} />
          {block.kind === "audio" ? (
            <FormField label={t("field.audio.transcript")} hint={t("field.audio.transcriptHint")}>
              <Textarea
                rows={8}
                value={readString(content, "transcript")}
                onChange={(e) => setContent((prev) => withValue(prev, "transcript", e.target.value))}
              />
            </FormField>
          ) : null}
          {richText}
        </>
      ) : block.kind === "external_link" ? (
        <ExternalLinkEditor content={content} onChange={setContent} />
      ) : block.kind === "quiz_placeholder" ? (
        <FormField label={t("field.quiz.note")} hint={t("field.quiz.hint")}>
          <Textarea
            rows={5}
            value={readString(content, "note")}
            onChange={(e) => setContent((prev) => withValue(prev, "note", e.target.value))}
          />
        </FormField>
      ) : (
        richText
      )}

      {/* Nested content blocks (C5). Gated by the AUTHORING_BLOCKS_ENABLED flag and the backend
          feature flag — renders nothing in production until both are on. */}
      <LessonBlocksPanel lessonId={block.id} lessonVersion={block.lock_version} />
    </div>
  );
}

function UnsupportedPanel({ kindLabel }: { kindLabel: string }) {
  const { t } = useAuthoringI18n();
  return (
    <div className="rounded-lg border border-dashed border-border bg-muted/30 p-6 text-center">
      <Sparkles className="mx-auto size-6 text-muted-foreground/50" aria-hidden />
      <p className="mt-2 text-sm font-medium">{t("editor.unsupported.title")}</p>
      <p className="mt-1 text-sm text-muted-foreground">{t("editor.unsupported.desc", { kind: kindLabel })}</p>
    </div>
  );
}
