"use client";

import { CheckCircle2, FileAudio, FileText, ImageIcon, Video } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { toast } from "@/components/ui/toast";
import type { AttachMediaInput, MediaAsset, MediaType } from "@/lib/media/media-api";
import { formatDuration } from "@/lib/media/media-format";
import { useMediaI18n } from "@/lib/media/media-i18n";
import { useAttachMedia, useMediaLibrary } from "@/lib/media/media-hooks";

const TYPE_ICON: Record<MediaType, typeof Video> = {
  video: Video,
  audio: FileAudio,
  image: ImageIcon,
  document: FileText,
};

export interface MediaAttachmentPickerProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Called with the chosen (ready) asset once a selection is made. */
  onSelect: (asset: MediaAsset) => void;
  /** Restrict the list to one media type (e.g. `video` for a lesson body). */
  type?: MediaType;
  /** Scope the list to a course public id. */
  courseId?: string;
  /** Highlight the currently-attached asset. */
  selectedId?: string | null;
  /**
   * Optional attachment context. When provided, choosing an asset also attaches it via the backend
   * before firing `onSelect`; otherwise the picker only returns the selected asset.
   */
  attachTo?: Omit<AttachMediaInput, "course_id"> & { courseId?: string };
}

/** Picker used inside authoring to attach an existing ready asset to content. Only ready assets are
 *  selectable — attaching a non-ready asset is rejected server-side (MediaNotReadyException). */
export function MediaAttachmentPicker({
  open,
  onOpenChange,
  onSelect,
  type,
  courseId,
  selectedId,
  attachTo,
}: MediaAttachmentPickerProps) {
  const { t } = useMediaI18n();
  const query = useMediaLibrary({ status: "ready", type, courseId, perPage: 50 }, open);
  const attach = useAttachMedia();

  const choose = (asset: MediaAsset) => {
    if (!attachTo) {
      onSelect(asset);
      onOpenChange(false);
      return;
    }
    attach.mutate(
      {
        id: asset.id,
        input: {
          attachable_type: attachTo.attachable_type,
          attachable_id: attachTo.attachable_id,
          role: attachTo.role,
          course_id: attachTo.courseId ?? courseId ?? null,
        },
      },
      {
        onSuccess: () => {
          onSelect(asset);
          onOpenChange(false);
        },
        onError: (error) => toast.error(errorMessage(error, t("media.error"))),
      },
    );
  };

  const assets = query.data?.data ?? [];

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{t("media.picker.title")}</DialogTitle>
          <DialogDescription>{t("media.picker.description")}</DialogDescription>
        </DialogHeader>

        {query.isPending ? (
          <div role="status" aria-live="polite" className="space-y-2">
            <span className="sr-only">{t("media.loading")}</span>
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-12 w-full rounded-md" />
            ))}
          </div>
        ) : query.isError ? (
          <div role="alert" className="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-center">
            <p className="text-sm text-destructive">{t("media.loadError")}</p>
            <Button className="mt-3" size="sm" variant="outline" onClick={() => query.refetch()}>
              {t("media.retry")}
            </Button>
          </div>
        ) : assets.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted-foreground">{t("media.picker.empty")}</p>
        ) : (
          <ul className="max-h-80 space-y-2 overflow-y-auto">
            {assets.map((asset) => {
              const Icon = TYPE_ICON[asset.type] ?? FileText;
              const isSelected = asset.id === selectedId;
              return (
                <li
                  key={asset.id}
                  className="flex items-center justify-between gap-3 rounded-md border p-2"
                  aria-current={isSelected ? "true" : undefined}
                >
                  <div className="flex min-w-0 items-center gap-2">
                    <Icon className="size-5 shrink-0 text-muted-foreground" aria-hidden />
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium">{asset.original_filename || asset.id}</p>
                      {asset.type === "video" || asset.type === "audio" ? (
                        <p className="text-xs text-muted-foreground">{formatDuration(asset.duration_seconds)}</p>
                      ) : null}
                    </div>
                  </div>
                  {isSelected ? (
                    <span className="flex items-center gap-1 text-xs font-medium text-success">
                      <CheckCircle2 className="size-4" aria-hidden /> {t("media.picker.selected")}
                    </span>
                  ) : (
                    <Button
                      size="sm"
                      loading={attach.isPending && attach.variables?.id === asset.id}
                      onClick={() => choose(asset)}
                    >
                      {t("media.picker.select")}
                    </Button>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </DialogContent>
    </Dialog>
  );
}
