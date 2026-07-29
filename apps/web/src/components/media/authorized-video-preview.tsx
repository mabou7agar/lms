"use client";

import type { ReactNode } from "react";
import { AlertTriangle, Loader2, PlayCircle } from "lucide-react";
import { cn } from "@/lib/utils";
import type { MediaAsset } from "@/lib/media/media-api";
import { mediaPhase } from "@/lib/media/media-format";
import { useMediaI18n } from "@/lib/media/media-i18n";

export interface AuthorizedVideoPreviewProps {
  asset: Pick<MediaAsset, "status" | "is_ready" | "width" | "height" | "original_filename">;
  /**
   * Signed playback source, issued separately by the backend `PlaybackPort` (never embedded in the
   * asset resource). When absent, a ready asset shows a play affordance rather than an inline video.
   */
  src?: string;
  poster?: string;
  className?: string;
}

/**
 * Authorized video preview. All playback bytes go through the app's own signed/proxied source
 * (`src`), never a raw provider URL. Renders the correct state for processing, failed, and ready
 * assets so a preview never claims a video is playable before the server says so.
 */
export function AuthorizedVideoPreview({ asset, src, poster, className }: AuthorizedVideoPreviewProps) {
  const { t } = useMediaI18n();
  const phase = mediaPhase(asset);

  if (phase === "processing" || phase === "awaiting") {
    return (
      <Placeholder className={className} role="status">
        <Loader2 className="size-6 animate-spin text-muted-foreground" aria-hidden />
        <p className="text-sm text-muted-foreground">{t("media.preview.processing")}</p>
      </Placeholder>
    );
  }

  if (phase === "failed") {
    return (
      <Placeholder className={className} role="alert">
        <AlertTriangle className="size-6 text-destructive" aria-hidden />
        <p className="text-sm text-destructive">{t("media.preview.failed")}</p>
      </Placeholder>
    );
  }

  if (!src) {
    return (
      <Placeholder className={className}>
        <PlayCircle className="size-8 text-muted-foreground" aria-hidden />
        <p className="text-sm text-muted-foreground">{t("media.preview.unavailable")}</p>
      </Placeholder>
    );
  }

  return (
    <video
      controls
      preload="metadata"
      poster={poster}
      className={cn("aspect-video w-full rounded-lg bg-black", className)}
      aria-label={asset.original_filename ?? t("media.phase.ready")}
    >
      <source src={src} />
    </video>
  );
}

function Placeholder({
  children,
  className,
  role,
}: {
  children: ReactNode;
  className?: string;
  role?: "status" | "alert";
}) {
  return (
    <div
      role={role}
      className={cn(
        "flex aspect-video w-full flex-col items-center justify-center gap-2 rounded-lg border border-dashed bg-muted/30",
        className,
      )}
    >
      {children}
    </div>
  );
}
