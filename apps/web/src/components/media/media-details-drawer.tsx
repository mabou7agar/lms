"use client";

import { useState } from "react";
import { RotateCcw, Trash2 } from "lucide-react";
import { ApiRequestError } from "@/lib/api/client";
import { errorMessage } from "@/lib/api/errors";
import {
  Drawer,
  DrawerContent,
  DrawerDescription,
  DrawerFooter,
  DrawerHeader,
  DrawerTitle,
} from "@/components/ui/drawer";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Skeleton } from "@/components/ui/skeleton";
import { toast } from "@/components/ui/toast";
import type { MediaAsset } from "@/lib/media/media-api";
import { formatBytes, formatDuration, mediaPhase } from "@/lib/media/media-format";
import { useMediaI18n } from "@/lib/media/media-i18n";
import { useDeleteMedia, useMediaAsset, useRetryMedia } from "@/lib/media/media-hooks";
import { CaptionManager } from "./caption-manager";
import { MediaStatusBadge } from "./media-status-badge";

export interface MediaDetailsDrawerProps {
  /** Public id of the asset to show, or null when closed. */
  mediaId: string | null;
  canManage: boolean;
  onOpenChange: (open: boolean) => void;
}

/** True when a delete was refused because the asset is still attached (MediaInUseException, 409). */
function isInUse(error: unknown): boolean {
  return error instanceof ApiRequestError && (error.code === "MEDIA_IN_USE" || error.status === 409);
}

export function MediaDetailsDrawer({ mediaId, canManage, onOpenChange }: MediaDetailsDrawerProps) {
  const { t } = useMediaI18n();
  const open = mediaId !== null;
  const query = useMediaAsset(mediaId, 4000, open);
  const del = useDeleteMedia();
  const retry = useRetryMedia();

  const [confirmOpen, setConfirmOpen] = useState(false);
  const [forceOpen, setForceOpen] = useState(false);

  const asset = query.data;

  const runDelete = (force: boolean) => {
    if (!mediaId) return;
    del.mutate(
      { id: mediaId, force },
      {
        onSuccess: () => {
          toast.success(t("media.deletedToast"));
          setConfirmOpen(false);
          setForceOpen(false);
          onOpenChange(false);
        },
        onError: (error) => {
          if (!force && isInUse(error)) {
            setConfirmOpen(false);
            setForceOpen(true);
            return;
          }
          toast.error(errorMessage(error, t("media.error")));
        },
      },
    );
  };

  return (
    <Drawer open={open} onOpenChange={onOpenChange}>
      <DrawerContent className="mx-auto max-h-[85vh] w-full max-w-2xl overflow-y-auto">
        <DrawerHeader>
          <DrawerTitle>{t("media.details.title")}</DrawerTitle>
          <DrawerDescription className="sr-only">{t("media.details.title")}</DrawerDescription>
        </DrawerHeader>

        <div className="space-y-6 px-4 pb-4">
          {query.isPending ? (
            <div role="status" aria-live="polite" className="space-y-3">
              <span className="sr-only">{t("media.loading")}</span>
              <Skeleton className="h-6 w-2/3" />
              <Skeleton className="h-24 w-full" />
            </div>
          ) : query.isError || !asset ? (
            <div role="alert" className="rounded-lg border border-destructive/40 bg-destructive/5 p-6 text-center">
              <p className="text-sm text-destructive">{t("media.loadError")}</p>
              <Button className="mt-3" size="sm" variant="outline" onClick={() => query.refetch()}>
                {t("media.retry")}
              </Button>
            </div>
          ) : (
            <>
              <Details asset={asset} />
              <UsageSection asset={asset} />
              {asset.type === "video" || asset.type === "audio" ? (
                <CaptionManager mediaId={asset.id} canManage={canManage} />
              ) : null}
            </>
          )}
        </div>

        <DrawerFooter className="flex-row justify-end gap-2">
          {asset && canManage && mediaPhase(asset) === "failed" ? (
            <Button
              variant="outline"
              loading={retry.isPending}
              onClick={() =>
                retry.mutate(asset.id, {
                  onSuccess: () => toast.success(t("media.card.retry")),
                  onError: (error) => toast.error(errorMessage(error, t("media.error"))),
                })
              }
            >
              <RotateCcw className="size-4" aria-hidden /> {t("media.details.retry")}
            </Button>
          ) : null}
          {asset && canManage ? (
            <Button variant="destructive" onClick={() => setConfirmOpen(true)}>
              <Trash2 className="size-4" aria-hidden /> {t("media.details.delete")}
            </Button>
          ) : null}
          <Button variant="ghost" onClick={() => onOpenChange(false)}>
            {t("media.close")}
          </Button>
        </DrawerFooter>
      </DrawerContent>

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title={t("media.delete.title")}
        description={t("media.delete.body")}
        confirmLabel={t("media.delete.confirm")}
        loading={del.isPending}
        onConfirm={() => runDelete(false)}
      />

      <ConfirmDialog
        open={forceOpen}
        onOpenChange={setForceOpen}
        title={t("media.delete.inUseTitle")}
        description={t("media.delete.inUseBody")}
        confirmLabel={t("media.delete.force")}
        loading={del.isPending}
        onConfirm={() => runDelete(true)}
      />
    </Drawer>
  );
}

function Details({ asset }: { asset: MediaAsset }) {
  const { t } = useMediaI18n();
  const rows: [string, string][] = [
    [t("media.details.filename"), asset.original_filename || "—"],
    [t("media.details.type"), t(`media.type.${asset.type}`)],
    [t("media.details.provider"), asset.provider],
    [t("media.details.size"), formatBytes(asset.size_bytes)],
    [t("media.details.duration"), formatDuration(asset.duration_seconds)],
    [
      t("media.details.dimensions"),
      asset.width && asset.height ? `${asset.width}×${asset.height}` : "—",
    ],
    [t("media.details.created"), asset.created_at ? new Date(asset.created_at).toLocaleString() : "—"],
  ];

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-medium">{t("media.details.status")}</span>
        <MediaStatusBadge asset={asset} />
      </div>
      <dl className="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
        {rows.map(([label, value]) => (
          <div key={label} className="flex justify-between gap-2 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="truncate font-medium">{value}</dd>
          </div>
        ))}
      </dl>
      {asset.failure_message ? (
        <p className="rounded-md border border-destructive/40 bg-destructive/5 p-2 text-xs text-destructive">
          {t("media.details.failureReason")}: {asset.failure_message}
        </p>
      ) : null}
    </div>
  );
}

/**
 * Usage references. The frozen backend does not expose a list-attachments endpoint on the asset, so
 * this reflects the delete-guard contract (MediaInUseException) rather than an enumerated list.
 */
function UsageSection({ asset }: { asset: MediaAsset }) {
  const { t } = useMediaI18n();
  void asset;
  return (
    <section aria-label={t("media.details.usage")} className="space-y-2">
      <h3 className="text-sm font-semibold">{t("media.details.usage")}</h3>
      <p className="text-sm text-muted-foreground">{t("media.details.usageEmpty")}</p>
    </section>
  );
}
