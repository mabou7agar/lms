"use client";

import { useState } from "react";
import { FilmIcon, UploadCloud } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useAuth } from "@/lib/auth/auth-context";
import { Button } from "@/components/ui/button";
import { Pagination } from "@/components/ui/pagination";
import { Skeleton } from "@/components/ui/skeleton";
import { toast } from "@/components/ui/toast";
import type { MediaAsset, MediaStatus, MediaType } from "@/lib/media/media-api";
import { canManageMedia } from "@/lib/media/media-format";
import { useMediaI18n } from "@/lib/media/media-i18n";
import { useMediaLibrary, useRetryMedia } from "@/lib/media/media-hooks";
import { MediaCard } from "./media-card";
import { MediaDetailsDrawer } from "./media-details-drawer";
import { MediaUploadDialog } from "./media-upload-dialog";

const TYPE_FILTERS: (MediaType | "all")[] = ["all", "video", "audio", "image", "document"];
const STATUS_FILTERS: (MediaStatus | "all")[] = ["all", "processing", "ready", "failed"];

export interface MediaLibraryPanelProps {
  /** Optional course scope (public id). When set, the library and uploads are scoped to it. */
  courseId?: string;
  /** Purpose used for uploads from this panel. Defaults to `lesson_video`. */
  uploadPurpose?: string;
}

export function MediaLibraryPanel({ courseId, uploadPurpose = "lesson_video" }: MediaLibraryPanelProps) {
  const { t } = useMediaI18n();
  const { user } = useAuth();
  const canManage = canManageMedia(user);

  const [type, setType] = useState<MediaType | "all">("all");
  const [status, setStatus] = useState<MediaStatus | "all">("all");
  const [page, setPage] = useState(1);
  const [uploadOpen, setUploadOpen] = useState(false);
  const [activeId, setActiveId] = useState<string | null>(null);

  const filters = {
    type: type === "all" ? undefined : type,
    status: status === "all" ? undefined : status,
    courseId,
    page,
  };
  const query = useMediaLibrary(filters);
  const retry = useRetryMedia();

  const onRetry = (asset: MediaAsset) =>
    retry.mutate(asset.id, {
      onSuccess: () => toast.success(t("media.card.retry")),
      onError: (error) => toast.error(errorMessage(error, t("media.error"))),
    });

  const assets = query.data?.data ?? [];
  const meta = query.data?.meta;

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-semibold">{t("media.title")}</h1>
          <p className="mt-1 text-sm text-muted-foreground">{t("media.subtitle")}</p>
        </div>
        {canManage ? (
          <Button onClick={() => setUploadOpen(true)}>
            <UploadCloud className="size-4" aria-hidden /> {t("media.upload")}
          </Button>
        ) : null}
      </header>

      <div className="flex flex-wrap items-center gap-4">
        <FilterRow
          label={t("media.filter.type")}
          value={type}
          options={TYPE_FILTERS}
          onChange={(v) => {
            setType(v);
            setPage(1);
          }}
          render={(v) => (v === "all" ? t("media.filter.all") : t(`media.type.${v}`))}
        />
        <FilterRow
          label={t("media.filter.status")}
          value={status}
          options={STATUS_FILTERS}
          onChange={(v) => {
            setStatus(v);
            setPage(1);
          }}
          render={(v) => (v === "all" ? t("media.filter.all") : t(`media.phase.${v === "ready" ? "ready" : v}`))}
        />
      </div>

      {query.isPending ? (
        <div role="status" aria-live="polite" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <span className="sr-only">{t("media.loading")}</span>
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-40 w-full rounded-lg" />
          ))}
        </div>
      ) : query.isError ? (
        <div role="alert" className="rounded-lg border border-destructive/40 bg-destructive/5 p-6 text-center">
          <p className="text-sm text-destructive">{t("media.loadError")}</p>
          <Button className="mt-3" variant="outline" size="sm" onClick={() => query.refetch()}>
            {t("media.retry")}
          </Button>
        </div>
      ) : assets.length === 0 ? (
        <div className="flex flex-col items-center gap-3 rounded-lg border border-dashed p-10 text-center">
          <FilmIcon className="size-8 text-muted-foreground" aria-hidden />
          <p className="text-sm text-muted-foreground">{t("media.empty")}</p>
        </div>
      ) : (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {assets.map((asset) => (
              <MediaCard
                key={asset.id}
                asset={asset}
                onOpen={(a) => setActiveId(a.id)}
                onRetry={canManage ? onRetry : undefined}
                retrying={retry.isPending && retry.variables === asset.id}
              />
            ))}
          </div>
          {meta && meta.last_page > 1 ? (
            <Pagination page={meta.current_page} lastPage={meta.last_page} onPageChange={setPage} />
          ) : null}
        </>
      )}

      <MediaUploadDialog
        open={uploadOpen}
        onOpenChange={setUploadOpen}
        purpose={uploadPurpose}
        courseId={courseId}
      />

      <MediaDetailsDrawer
        mediaId={activeId}
        canManage={canManage}
        onOpenChange={(open) => {
          if (!open) setActiveId(null);
        }}
      />
    </div>
  );
}

function FilterRow<T extends string>({
  label,
  value,
  options,
  onChange,
  render,
}: {
  label: string;
  value: T;
  options: T[];
  onChange: (value: T) => void;
  render: (value: T) => string;
}) {
  return (
    <div className="flex items-center gap-2">
      <span className="text-xs font-medium text-muted-foreground">{label}</span>
      <div className="flex flex-wrap gap-1">
        {options.map((option) => (
          <Button
            key={option}
            type="button"
            size="sm"
            variant={value === option ? "default" : "outline"}
            aria-pressed={value === option}
            onClick={() => onChange(option)}
          >
            {render(option)}
          </Button>
        ))}
      </div>
    </div>
  );
}
