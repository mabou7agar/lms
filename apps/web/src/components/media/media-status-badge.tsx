"use client";

import { Badge } from "@/components/ui/badge";
import type { MediaAsset } from "@/lib/media/media-api";
import { mediaPhase, phaseBadgeVariant } from "@/lib/media/media-format";
import { useMediaI18n } from "@/lib/media/media-i18n";

/** Lifecycle badge driven off the server's status/`is_ready`, never recomputed from other fields. */
export function MediaStatusBadge({ asset }: { asset: Pick<MediaAsset, "status" | "is_ready"> }) {
  const { t } = useMediaI18n();
  const phase = mediaPhase(asset);
  return <Badge variant={phaseBadgeVariant(phase)}>{t(`media.phase.${phase}`)}</Badge>;
}
