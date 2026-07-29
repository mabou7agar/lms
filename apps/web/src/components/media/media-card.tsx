"use client";

import { FileAudio, FileText, ImageIcon, RotateCcw, Video } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import type { MediaAsset, MediaType } from "@/lib/media/media-api";
import { formatBytes, formatDuration, mediaPhase } from "@/lib/media/media-format";
import { useMediaI18n } from "@/lib/media/media-i18n";
import { MediaStatusBadge } from "./media-status-badge";

const TYPE_ICON: Record<MediaType, typeof Video> = {
  video: Video,
  audio: FileAudio,
  image: ImageIcon,
  document: FileText,
};

export interface MediaCardProps {
  asset: MediaAsset;
  onOpen: (asset: MediaAsset) => void;
  /** Retry handler for a failed asset. Omit to hide the inline retry (no manage permission). */
  onRetry?: (asset: MediaAsset) => void;
  retrying?: boolean;
}

/** A single library tile. Renders every lifecycle state: awaiting, processing (with progress),
 *  ready, and failed (with an inline retry when the viewer may manage). */
export function MediaCard({ asset, onOpen, onRetry, retrying }: MediaCardProps) {
  const { t } = useMediaI18n();
  const phase = mediaPhase(asset);
  const Icon = TYPE_ICON[asset.type] ?? FileText;

  return (
    <Card className="flex flex-col">
      <CardContent className="flex flex-1 flex-col gap-3 p-4">
        <div className="flex items-start justify-between gap-2">
          <div className="flex min-w-0 items-center gap-2">
            <Icon className="size-5 shrink-0 text-muted-foreground" aria-hidden />
            <h3 className="truncate text-sm font-semibold">
              {asset.original_filename || t("media.card.untitled")}
            </h3>
          </div>
          <MediaStatusBadge asset={asset} />
        </div>

        {phase === "processing" ? (
          <div className="space-y-1">
            <Progress value={asset.processing_progress} label={t("media.phase.processing")} />
            <p className="text-xs text-muted-foreground">{t("media.card.processingHint")}</p>
          </div>
        ) : null}

        {phase === "failed" ? (
          <p className="text-xs text-destructive" role="alert">
            {asset.failure_message ?? t("media.phase.failed")}
          </p>
        ) : null}

        <dl className="mt-auto grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-muted-foreground">
          <div>
            <dt className="inline">{t("media.card.size")}: </dt>
            <dd className="inline">{formatBytes(asset.size_bytes)}</dd>
          </div>
          {asset.type === "video" || asset.type === "audio" ? (
            <div>
              <dt className="inline">{t("media.card.duration")}: </dt>
              <dd className="inline">{formatDuration(asset.duration_seconds)}</dd>
            </div>
          ) : null}
        </dl>

        <div className="flex flex-wrap gap-2">
          <Button size="sm" variant="outline" onClick={() => onOpen(asset)}>
            {t("media.card.details")}
          </Button>
          {phase === "failed" && onRetry ? (
            <Button size="sm" loading={retrying} onClick={() => onRetry(asset)}>
              <RotateCcw className="size-4" aria-hidden /> {t("media.card.retry")}
            </Button>
          ) : null}
        </div>
      </CardContent>
    </Card>
  );
}
