"use client";

import { useMemo, useState } from "react";
import { Eye, EyeOff } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { blockDef } from "@/lib/authoring/block-registry";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";
import {
  assembleContentI18n,
  isFormValid,
  parseFormValues,
  type BlockFormValues,
} from "@/lib/authoring/content-blocks/registry";
import type { BlockKind } from "@/lib/authoring/types";
import type { BlockContentI18n } from "@/lib/authoring/content-blocks/types";
import { ContentBlockEditor } from "./content-block-editor";
import { ContentBlockPreview } from "./content-block-preview";

/**
 * Add / edit dialog for a single content block. The typed editor (mirroring the backend
 * `BlockPayloadRules`) edits EN + AR; a live preview shows exactly what the learner will see. Save is
 * blocked until required fields carry an English value. Assembling the `content_i18n` map omits an
 * empty Arabic side so it falls back to English rather than failing the backend's per-locale rules.
 */
export function ContentBlockDialog({
  open,
  mode,
  kind,
  initial,
  saving,
  onOpenChange,
  onSubmit,
}: {
  open: boolean;
  mode: "add" | "edit";
  kind: BlockKind;
  initial?: BlockContentI18n;
  saving?: boolean;
  onOpenChange: (open: boolean) => void;
  onSubmit: (contentI18n: BlockContentI18n) => void | Promise<void>;
}) {
  const { t } = useAuthoringI18n();
  const kindLabel = t(blockDef(kind).labelKey);

  // Seed once per open so unsaved edits are kept while the dialog stays open (and while a save
  // retries). A fresh `open` (add vs a different block) remounts via the `key` in the parent.
  const seed = useMemo(() => parseFormValues(kind, initial ?? {}), [kind, initial]);
  const [values, setValues] = useState<BlockFormValues>(seed);
  const [showPreview, setShowPreview] = useState(false);

  const valid = isFormValid(kind, values);
  const previewContent = assembleContentI18n(kind, values);

  const submit = async () => {
    if (!valid || saving) return;
    await onSubmit(assembleContentI18n(kind, values));
  };

  return (
    <Dialog open={open} onOpenChange={(next) => (saving ? undefined : onOpenChange(next))}>
      <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>
            {t(mode === "add" ? "cblock.dialog.addTitle" : "cblock.dialog.editTitle", { kind: kindLabel })}
          </DialogTitle>
          <DialogDescription>{t("cblock.panel.desc")}</DialogDescription>
        </DialogHeader>

        <div className="space-y-5 py-2">
          <ContentBlockEditor kind={kind} values={values} onChange={setValues} />

          <div className="space-y-2 border-t border-border/60 pt-4">
            <Button type="button" variant="ghost" size="sm" onClick={() => setShowPreview((v) => !v)} className="gap-2">
              {showPreview ? <EyeOff className="size-4" aria-hidden /> : <Eye className="size-4" aria-hidden />}
              {t(showPreview ? "cblock.dialog.hidePreview" : "cblock.dialog.showPreview")}
            </Button>
            {showPreview ? (
              <div className="rounded-md border border-border/60 bg-muted/20 p-4">
                <ContentBlockPreview kind={kind} contentI18n={previewContent} />
              </div>
            ) : null}
          </div>
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
            {t("cblock.dialog.cancel")}
          </Button>
          <Button type="button" onClick={() => void submit()} disabled={!valid || saving}>
            {t("cblock.dialog.save")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
