"use client";

import { useState, type FormEvent } from "react";
import { Trash2 } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { toast } from "@/components/ui/toast";
import type { CaptionFormat, MediaCaption } from "@/lib/media/media-api";
import { useMediaI18n } from "@/lib/media/media-i18n";
import { useAddCaption, useCaptions, useDeleteCaption } from "@/lib/media/media-hooks";

const CAPTION_STATUS_VARIANT: Record<MediaCaption["status"], "success" | "secondary" | "destructive"> = {
  ready: "success",
  pending: "secondary",
  failed: "destructive",
};

export interface CaptionManagerProps {
  mediaId: string;
  canManage: boolean;
}

export function CaptionManager({ mediaId, canManage }: CaptionManagerProps) {
  const { t } = useMediaI18n();
  const query = useCaptions(mediaId);
  const add = useAddCaption(mediaId);
  const remove = useDeleteCaption(mediaId);

  const [language, setLanguage] = useState("");
  const [label, setLabel] = useState("");
  const [format, setFormat] = useState<CaptionFormat>("vtt");
  const [validationError, setValidationError] = useState<string | null>(null);
  const [pendingDelete, setPendingDelete] = useState<MediaCaption | null>(null);

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    if (!language.trim()) {
      setValidationError(t("media.captions.languageRequired"));
      return;
    }
    if (!label.trim()) {
      setValidationError(t("media.captions.labelRequired"));
      return;
    }
    setValidationError(null);
    add.mutate(
      { language: language.trim(), label: label.trim(), format },
      {
        onSuccess: () => {
          toast.success(t("media.captions.addedToast"));
          setLanguage("");
          setLabel("");
          setFormat("vtt");
        },
        onError: (error) => setValidationError(errorMessage(error, t("media.error"))),
      },
    );
  };

  const captions = query.data ?? [];

  return (
    <section aria-label={t("media.captions.title")} className="space-y-3">
      <h3 className="text-sm font-semibold">{t("media.captions.title")}</h3>

      {query.isPending ? (
        <div role="status" aria-live="polite">
          <Spinner size="sm" label={t("media.loading")} />
        </div>
      ) : query.isError ? (
        <div role="alert" className="flex items-center justify-between gap-2 rounded-md border border-destructive/40 bg-destructive/5 p-2">
          <span className="text-xs text-destructive">{t("media.captions.loadError")}</span>
          <Button size="sm" variant="outline" onClick={() => query.refetch()}>
            {t("media.retry")}
          </Button>
        </div>
      ) : captions.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t("media.captions.empty")}</p>
      ) : (
        <ul className="space-y-2">
          {captions.map((caption) => (
            <li key={caption.id} className="flex items-center justify-between gap-2 rounded-md border p-2">
              <div className="flex min-w-0 items-center gap-2">
                <span className="truncate text-sm font-medium">{caption.label}</span>
                <span className="text-xs uppercase text-muted-foreground">{caption.language}</span>
                <span className="text-xs uppercase text-muted-foreground">{caption.format}</span>
                <Badge variant={CAPTION_STATUS_VARIANT[caption.status]}>
                  {t(`media.captions.status.${caption.status}`)}
                </Badge>
              </div>
              {canManage ? (
                <Button
                  size="icon"
                  variant="ghost"
                  aria-label={t("media.captions.deleteTitle")}
                  onClick={() => setPendingDelete(caption)}
                >
                  <Trash2 className="size-4" aria-hidden />
                </Button>
              ) : null}
            </li>
          ))}
        </ul>
      )}

      {canManage ? (
        <form onSubmit={onSubmit} className="space-y-2 rounded-md border p-3">
          <div className="grid gap-2 sm:grid-cols-3">
            <Input
              aria-label={t("media.captions.language")}
              placeholder={t("media.captions.languagePlaceholder")}
              value={language}
              maxLength={35}
              onChange={(e) => setLanguage(e.target.value)}
            />
            <Input
              aria-label={t("media.captions.label")}
              placeholder={t("media.captions.labelPlaceholder")}
              value={label}
              maxLength={100}
              onChange={(e) => setLabel(e.target.value)}
            />
            <select
              aria-label={t("media.captions.format")}
              value={format}
              onChange={(e) => setFormat(e.target.value as CaptionFormat)}
              className="h-10 rounded-md border border-input bg-background px-3 text-sm"
            >
              <option value="vtt">VTT</option>
              <option value="srt">SRT</option>
            </select>
          </div>

          {validationError ? (
            <p role="alert" className="text-xs text-destructive">
              {validationError}
            </p>
          ) : null}

          <Button type="submit" size="sm" loading={add.isPending}>
            {t("media.captions.submit")}
          </Button>
        </form>
      ) : null}

      <ConfirmDialog
        open={pendingDelete !== null}
        onOpenChange={(open) => {
          if (!open) setPendingDelete(null);
        }}
        title={t("media.captions.deleteTitle")}
        description={t("media.captions.deleteBody", { label: pendingDelete?.label ?? "" })}
        confirmLabel={t("media.remove")}
        loading={remove.isPending}
        onConfirm={() => {
          if (!pendingDelete) return;
          remove.mutate(pendingDelete.id, {
            onSuccess: () => {
              toast.success(t("media.captions.removedToast"));
              setPendingDelete(null);
            },
            onError: (error) => {
              toast.error(errorMessage(error, t("media.error")));
              setPendingDelete(null);
            },
          });
        }}
      />
    </section>
  );
}
