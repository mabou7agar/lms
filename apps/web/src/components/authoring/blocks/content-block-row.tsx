"use client";

import { useState } from "react";
import { useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { Copy, Eye, EyeOff, GripVertical, MoreVertical, Pencil, Send, Trash2, Undo2 } from "lucide-react";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Icon } from "@/components/ui/icon";
import { blockDef } from "@/lib/authoring/block-registry";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";
import type { ContentBlock } from "@/lib/authoring/content-blocks/types";
import { StatusBadge } from "../status-badge";
import { ContentBlockPreview } from "./content-block-preview";

/**
 * One content block in the lesson's ordered list. Carries a keyboard-operable drag handle (the panel
 * owns the DndContext and persists the new order server-authoritatively), a type icon + label, the
 * publish state, an inline read-only preview toggle, and an actions menu (edit / duplicate / publish
 * toggle / delete). Delete is confirmed; everything else is a single action.
 */
export function ContentBlockRow({
  block,
  onEdit,
  onDuplicate,
  onDelete,
  onPublishToggle,
}: {
  block: ContentBlock;
  onEdit: () => void;
  onDuplicate: () => void;
  onDelete: () => void;
  onPublishToggle: (next: "draft" | "published") => void;
}) {
  const { t } = useAuthoringI18n();
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [showPreview, setShowPreview] = useState(false);
  const def = blockDef(block.type);
  const published = block.publish_state === "published";

  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: block.id,
    data: { type: "content-block" },
  });

  return (
    <div
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={`rounded-lg border bg-card ${isDragging ? "opacity-60" : "border-border"}`}
    >
      <div className="group flex items-center gap-1 px-1 py-1.5">
        <button
          type="button"
          className="flex size-6 shrink-0 cursor-grab touch-none items-center justify-center rounded text-muted-foreground/60 hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring active:cursor-grabbing"
          aria-label={t("action.dragHandle")}
          {...attributes}
          {...listeners}
        >
          <GripVertical className="size-4" aria-hidden />
        </button>

        <span className="text-muted-foreground">
          <Icon icon={def.icon} size="sm" />
        </span>
        <span className="min-w-0 flex-1 truncate text-sm font-medium">{t(def.labelKey)}</span>
        <StatusBadge state={block.publish_state} />

        <button
          type="button"
          onClick={() => setShowPreview((v) => !v)}
          className="flex size-7 shrink-0 items-center justify-center rounded text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
          aria-label={t(showPreview ? "cblock.dialog.hidePreview" : "cblock.preview")}
          aria-pressed={showPreview}
        >
          {showPreview ? <EyeOff className="size-4" aria-hidden /> : <Eye className="size-4" aria-hidden />}
        </button>

        <button
          type="button"
          onClick={onEdit}
          className="flex size-7 shrink-0 items-center justify-center rounded text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
          aria-label={t("cblock.edit")}
        >
          <Pencil className="size-4" aria-hidden />
        </button>

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              type="button"
              className="flex size-7 shrink-0 items-center justify-center rounded text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
              aria-label={t("cblock.more")}
            >
              <MoreVertical className="size-4" aria-hidden />
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-48">
            <DropdownMenuItem onSelect={onEdit} className="gap-2">
              <Pencil className="size-4" aria-hidden /> {t("cblock.edit")}
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={() => onPublishToggle(published ? "draft" : "published")} className="gap-2">
              {published ? <Undo2 className="size-4" aria-hidden /> : <Send className="size-4" aria-hidden />}
              {t(published ? "cblock.unpublish" : "cblock.publish")}
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={onDuplicate} className="gap-2">
              <Copy className="size-4" aria-hidden /> {t("cblock.duplicate")}
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem onSelect={() => setConfirmDelete(true)} className="gap-2 text-destructive">
              <Trash2 className="size-4" aria-hidden /> {t("cblock.delete")}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      {showPreview ? (
        <div className="border-t border-border/60 p-3">
          <ContentBlockPreview kind={block.type} contentI18n={block.content_i18n} />
        </div>
      ) : null}

      <ConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        title={t("cblock.confirmDelete.title")}
        description={t("cblock.confirmDelete.desc")}
        confirmLabel={t("cblock.delete")}
        cancelLabel={t("cblock.dialog.cancel")}
        confirmVariant="destructive"
        onConfirm={onDelete}
      />
    </div>
  );
}
