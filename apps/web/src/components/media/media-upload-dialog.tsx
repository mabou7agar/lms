"use client";

import { useRef, useState, type ChangeEvent, type DragEvent } from "react";
import { CheckCircle2, RotateCcw, UploadCloud, X } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { Spinner } from "@/components/ui/spinner";
import { cn } from "@/lib/utils";
import { useMediaI18n } from "@/lib/media/media-i18n";
import { useMediaUploader, type UploadItem } from "@/lib/media/media-hooks";
import type { UploadTransport } from "@/lib/media/media-upload";

export interface MediaUploadDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  purpose: string;
  courseId?: string;
  /** Injected transport for tests; production defaults to the XHR transport. */
  transport?: UploadTransport;
}

/**
 * Drag-and-drop upload surface. Each dropped file runs the direct-upload state machine
 * (create → upload with byte progress → finalize → processing → ready) and shows its own row with
 * a retry on failure. The dialog does not stream bytes itself — that is the injectable transport.
 */
export function MediaUploadDialog({ open, onOpenChange, purpose, courseId, transport }: MediaUploadDialogProps) {
  const { t } = useMediaI18n();
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragging, setDragging] = useState(false);
  const uploader = useMediaUploader({ purpose, courseId, transport });

  const onFiles = (files: FileList | null) => {
    if (!files || files.length === 0) return;
    uploader.enqueue(Array.from(files));
  };

  const onDrop = (event: DragEvent<HTMLDivElement>) => {
    event.preventDefault();
    setDragging(false);
    onFiles(event.dataTransfer.files);
  };

  const onInputChange = (event: ChangeEvent<HTMLInputElement>) => {
    onFiles(event.target.files);
    event.target.value = "";
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{t("media.upload.title")}</DialogTitle>
          <DialogDescription>{t("media.upload.description")}</DialogDescription>
        </DialogHeader>

        <div
          onDragOver={(e) => {
            e.preventDefault();
            setDragging(true);
          }}
          onDragLeave={() => setDragging(false)}
          onDrop={onDrop}
          className={cn(
            "flex flex-col items-center gap-2 rounded-lg border border-dashed p-8 text-center transition-colors",
            dragging ? "border-primary bg-primary/5" : "border-border",
          )}
        >
          <UploadCloud className="size-8 text-muted-foreground" aria-hidden />
          <p className="text-sm text-muted-foreground">
            {t("media.upload.drop")}{" "}
            <button
              type="button"
              className="font-medium text-primary underline underline-offset-2"
              onClick={() => inputRef.current?.click()}
            >
              {t("media.upload.browse")}
            </button>
          </p>
          <input
            ref={inputRef}
            type="file"
            multiple
            className="sr-only"
            aria-label={t("media.upload.browse")}
            onChange={onInputChange}
          />
        </div>

        <ul className="max-h-64 space-y-2 overflow-y-auto">
          {uploader.items.length === 0 ? (
            <li className="py-2 text-center text-sm text-muted-foreground">{t("media.upload.empty")}</li>
          ) : (
            uploader.items.map((item) => (
              <UploadRow key={item.id} item={item} onRetry={uploader.retry} onRemove={uploader.remove} />
            ))
          )}
        </ul>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t("media.upload.done")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function UploadRow({
  item,
  onRetry,
  onRemove,
}: {
  item: UploadItem;
  onRetry: (id: string) => void;
  onRemove: (id: string) => void;
}) {
  const { t } = useMediaI18n();
  const active = item.phase === "creating" || item.phase === "uploading" || item.phase === "finalizing";

  const label =
    item.phase === "uploading"
      ? t("media.upload.phase.uploading", { percent: item.progress })
      : t(`media.upload.phase.${item.phase}`);

  return (
    <li className="rounded-md border p-3">
      <div className="flex items-center justify-between gap-2">
        <span className="truncate text-sm font-medium">{item.file.name}</span>
        <div className="flex items-center gap-2">
          {active ? <Spinner size="sm" label={label} /> : null}
          {item.phase === "ready" ? <CheckCircle2 className="size-4 text-success" aria-hidden /> : null}
          <button
            type="button"
            aria-label={t("media.remove")}
            className="text-muted-foreground hover:text-foreground"
            onClick={() => onRemove(item.id)}
          >
            <X className="size-4" aria-hidden />
          </button>
        </div>
      </div>

      {item.phase === "uploading" ? (
        <Progress className="mt-2" value={item.progress} label={label} />
      ) : (
        <p className={cn("mt-1 text-xs", item.phase === "failed" ? "text-destructive" : "text-muted-foreground")}>
          {label}
        </p>
      )}

      {item.phase === "failed" ? (
        <div className="mt-2 flex items-center justify-between gap-2">
          <span role="alert" className="truncate text-xs text-destructive">
            {item.error ?? t("media.upload.phase.failed")}
          </span>
          <Button size="sm" variant="outline" onClick={() => onRetry(item.id)}>
            <RotateCcw className="size-4" aria-hidden /> {t("media.upload.retry")}
          </Button>
        </div>
      ) : null}
    </li>
  );
}
