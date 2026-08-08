"use client";

import { useState } from "react";
import { Layers, Lock, TriangleAlert } from "lucide-react";
import {
  closestCenter,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from "@dnd-kit/core";
import { arrayMove, SortableContext, sortableKeyboardCoordinates, verticalListSortingStrategy } from "@dnd-kit/sortable";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";
import { useFeatureFlag } from "@/lib/flags/hooks";
import { useLessonBlocks } from "@/lib/authoring/content-blocks/hooks";
import type { BlockKind } from "@/lib/authoring/types";
import type { BlockContentI18n, ContentBlock } from "@/lib/authoring/content-blocks/types";
import { AddContentBlockMenu } from "./add-content-block-menu";
import { BlocksConflictBanner } from "./blocks-conflict-banner";
import { ContentBlockDialog } from "./content-block-dialog";
import { ContentBlockRow } from "./content-block-row";

/** Presentation flag key — gates the nested content-blocks authoring panel. */
export const BLOCKS_FLAG = "AUTHORING_BLOCKS_ENABLED";

type DialogState = { mode: "add"; kind: BlockKind } | { mode: "edit"; block: ContentBlock } | null;

/**
 * Nested content-blocks authoring panel for a single lesson. Lists the lesson's ordered blocks and
 * owns create / edit / delete / duplicate / publish / server-authoritative reorder, plus the
 * non-destructive 409 conflict UX.
 *
 * Double-gated so production is unchanged until the feature is turned on:
 *   1. the presentation flag `AUTHORING_BLOCKS_ENABLED` (an admin can hide it without any API call), and
 *   2. the backend `authoring.blocks_enabled` flag, whose 404 surfaces as `featureDisabled` here.
 */
export function LessonBlocksPanel({ lessonId, lessonVersion }: { lessonId: string; lessonVersion: number }) {
  const { t } = useAuthoringI18n();
  const flagEnabled = useFeatureFlag(BLOCKS_FLAG);

  const {
    blocks,
    isLoading,
    isError,
    featureDisabled,
    permissionDenied,
    refetch,
    conflict,
    reloadAfterConflict,
    dismissConflict,
    addBlock,
    editBlock,
    removeBlock,
    duplicateBlock,
    publishBlock,
    reorderBlocks,
  } = useLessonBlocks(lessonId, lessonVersion, flagEnabled);

  const [dialog, setDialog] = useState<DialogState>(null);
  const [saving, setSaving] = useState(false);

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  // Presentation flag off, or the backend feature flag is off (list 404s): render nothing at all so
  // the lesson editor is byte-for-byte unchanged in production.
  if (!flagEnabled || featureDisabled) return null;

  function onDragEnd(e: DragEndEvent) {
    const { active, over } = e;
    if (!over || active.id === over.id) return;
    const ids = blocks.map((b) => b.id);
    const from = ids.indexOf(String(active.id));
    const to = ids.indexOf(String(over.id));
    if (from === -1 || to === -1 || from === to) return;
    void reorderBlocks(arrayMove(ids, from, to));
  }

  async function submitDialog(contentI18n: BlockContentI18n) {
    if (!dialog) return;
    setSaving(true);
    try {
      if (dialog.mode === "add") {
        const created = await addBlock(dialog.kind, contentI18n);
        if (created) setDialog(null);
      } else {
        const ok = await editBlock(dialog.block.id, { content_i18n: contentI18n });
        if (ok) setDialog(null);
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <section className="space-y-3 rounded-lg border border-border bg-card/40 p-4" aria-label={t("cblock.panel.title")}>
      <header className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 className="flex items-center gap-2 text-sm font-semibold">
            <Layers className="size-4 text-muted-foreground" aria-hidden />
            {t("cblock.panel.title")}
          </h3>
          <p className="mt-0.5 text-xs text-muted-foreground">{t("cblock.panel.desc")}</p>
        </div>
        {!permissionDenied ? <AddContentBlockMenu onAdd={(kind) => setDialog({ mode: "add", kind })} /> : null}
      </header>

      {conflict ? <BlocksConflictBanner onReload={reloadAfterConflict} onDismiss={dismissConflict} /> : null}

      {permissionDenied ? (
        <div role="alert" className="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-3 py-4 text-sm text-muted-foreground">
          <Lock className="size-4 shrink-0" aria-hidden />
          {t("cblock.permissionDenied")}
        </div>
      ) : isLoading ? (
        <div className="space-y-2" role="status" aria-live="polite" aria-busy="true">
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
        </div>
      ) : isError ? (
        <div className="flex flex-col items-center gap-3 rounded-md border border-border bg-muted/20 px-4 py-8 text-center">
          <TriangleAlert className="size-6 text-muted-foreground/60" aria-hidden />
          <p className="text-sm text-muted-foreground">{t("cblock.loadError")}</p>
          <Button size="sm" variant="outline" onClick={refetch}>
            {t("cblock.retry")}
          </Button>
        </div>
      ) : blocks.length === 0 ? (
        <div className="flex flex-col items-center gap-3 rounded-md border border-dashed border-border bg-muted/20 px-4 py-8 text-center">
          <Layers className="size-7 text-muted-foreground/50" aria-hidden />
          <div>
            <p className="text-sm font-medium">{t("cblock.empty.title")}</p>
            <p className="mt-1 text-xs text-muted-foreground">{t("cblock.empty.desc")}</p>
          </div>
          <AddContentBlockMenu onAdd={(kind) => setDialog({ mode: "add", kind })} />
        </div>
      ) : (
        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
          <SortableContext items={blocks.map((b) => b.id)} strategy={verticalListSortingStrategy}>
            <ul className="space-y-2">
              {blocks.map((block) => (
                <li key={block.id}>
                  <ContentBlockRow
                    block={block}
                    onEdit={() => setDialog({ mode: "edit", block })}
                    onDuplicate={() => void duplicateBlock(block.id)}
                    onDelete={() => void removeBlock(block.id)}
                    onPublishToggle={(next) => void publishBlock(block.id, next)}
                  />
                </li>
              ))}
            </ul>
          </SortableContext>
        </DndContext>
      )}

      {dialog ? (
        <ContentBlockDialog
          key={dialog.mode === "edit" ? dialog.block.id : `add-${dialog.kind}`}
          open
          mode={dialog.mode}
          kind={dialog.mode === "edit" ? dialog.block.type : dialog.kind}
          initial={dialog.mode === "edit" ? dialog.block.content_i18n : undefined}
          saving={saving}
          onOpenChange={(next) => {
            if (!next && !saving) setDialog(null);
          }}
          onSubmit={submitDialog}
        />
      ) : null}
    </section>
  );
}
