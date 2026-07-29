"use client";

import { useState } from "react";
import { Camera, Copy, GitFork, Info, RotateCcw, Undo2 } from "lucide-react";
import {
  Badge,
  Button,
  ConfirmDialog,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Pagination,
  Spinner,
} from "@/components/ui";
import { useVersioningI18n } from "@/lib/authoring/versioning-i18n";
import { formatVersionDate, shortChecksum } from "@/lib/authoring/versioning-format";
import type { ContentVersion, VersionReason } from "@/lib/authoring/versioning-api";
import {
  useCloneVersion,
  useCreateSnapshot,
  useForkVersion,
  useRestoreVersion,
  useRollbackVersion,
  useVersionHistory,
} from "@/lib/authoring/versioning-hooks";
import { VersionDetailsDrawer } from "./version-details-drawer";

type BadgeVariant = "default" | "secondary" | "outline" | "info" | "warning";

const REASON_VARIANT: Record<VersionReason, BadgeVariant> = {
  manual: "default",
  safety: "warning",
  rollback: "info",
  clone: "secondary",
  fork: "outline",
};

export interface VersionHistoryPanelProps {
  courseId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** May create snapshots, clone and fork. */
  canManage?: boolean;
  /** May restore/rollback (a stronger, draft-replacing action). */
  canRestore?: boolean;
}

export function VersionHistoryPanel({
  courseId,
  open,
  onOpenChange,
  canManage = true,
  canRestore = true,
}: VersionHistoryPanelProps) {
  const { t } = useVersioningI18n();
  const [page, setPage] = useState(1);

  const history = useVersionHistory(courseId, page, open);
  const create = useCreateSnapshot(courseId);
  const restore = useRestoreVersion(courseId);
  const rollback = useRollbackVersion(courseId);
  const clone = useCloneVersion(courseId);
  const fork = useForkVersion(courseId);

  const [snapshotOpen, setSnapshotOpen] = useState(false);
  const [details, setDetails] = useState<ContentVersion | null>(null);
  const [restoreTarget, setRestoreTarget] = useState<ContentVersion | null>(null);
  const [rollbackTarget, setRollbackTarget] = useState<ContentVersion | null>(null);
  const [cloneTarget, setCloneTarget] = useState<ContentVersion | null>(null);
  const [forkTarget, setForkTarget] = useState<ContentVersion | null>(null);

  const actionError =
    create.error ?? restore.error ?? rollback.error ?? clone.error ?? fork.error ?? null;

  const versions = history.data?.data ?? [];
  const meta = history.data?.meta;

  const doRestore = async () => {
    if (!restoreTarget) return;
    try {
      await restore.mutateAsync(restoreTarget.id);
      setRestoreTarget(null);
    } catch {
      /* surfaced via the alert below */
    }
  };

  const doRollback = async () => {
    if (!rollbackTarget) return;
    try {
      await rollback.mutateAsync(rollbackTarget.id);
      setRollbackTarget(null);
    } catch {
      /* surfaced via the alert below */
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>{t("versions.title")}</DialogTitle>
          <DialogDescription>{t("versions.subtitle")}</DialogDescription>
        </DialogHeader>

        {canManage ? (
          <div className="flex justify-end">
            <Button size="sm" onClick={() => setSnapshotOpen(true)}>
              <Camera className="size-4" aria-hidden />
              {t("versions.create")}
            </Button>
          </div>
        ) : null}

        {actionError ? (
          <div role="alert" className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">
            {actionError.message}
          </div>
        ) : null}

        {history.isPending ? (
          <div role="status" className="flex items-center justify-center py-10">
            <Spinner />
            <span className="sr-only">{t("versions.title")}</span>
          </div>
        ) : history.isError ? (
          <div className="flex flex-col items-center gap-3 py-8 text-center">
            <p className="text-sm text-muted-foreground">{t("versions.loadError")}</p>
            <Button variant="outline" size="sm" onClick={() => history.refetch()}>
              {t("versions.retry")}
            </Button>
          </div>
        ) : versions.length === 0 ? (
          <p className="py-10 text-center text-sm text-muted-foreground">{t("versions.empty")}</p>
        ) : (
          <ul className="divide-y divide-border rounded-md border border-border">
            {versions.map((version) => (
              <li key={version.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 p-3">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <span className="font-semibold">{t("versions.number", { n: version.version_number })}</span>
                    <Badge variant={REASON_VARIANT[version.reason]}>{t(`versions.reason.${version.reason}`)}</Badge>
                    {version.label ? <span className="truncate text-sm text-muted-foreground">{version.label}</span> : null}
                  </div>
                  <div className="mt-0.5 flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                    <span>
                      {version.created_by !== null
                        ? t("versions.by", { id: version.created_by })
                        : t("versions.bySystem")}
                    </span>
                    <span>{formatVersionDate(version.created_at)}</span>
                    <code className="font-mono">{t("versions.checksum", { short: shortChecksum(version.checksum) })}</code>
                    <span>
                      {version.source
                        ? version.source.from_other_course
                          ? t("versions.sourceForked", { n: version.source.version_number })
                          : t("versions.source", { n: version.source.version_number })
                        : t("versions.noSource")}
                    </span>
                    <span>
                      {t("versions.counts", {
                        sections: version.summary.sections,
                        lessons: version.summary.lessons,
                        blocks: version.summary.blocks,
                        modules: version.summary.modules,
                      })}
                    </span>
                  </div>
                </div>

                <div className="flex flex-wrap items-center gap-1">
                  <Button variant="ghost" size="sm" onClick={() => setDetails(version)}>
                    <Info className="size-4" aria-hidden />
                    {t("versions.action.details")}
                  </Button>
                  {canRestore ? (
                    <>
                      <Button variant="ghost" size="sm" onClick={() => setRestoreTarget(version)}>
                        <RotateCcw className="size-4" aria-hidden />
                        {t("versions.action.restore")}
                      </Button>
                      <Button variant="ghost" size="sm" onClick={() => setRollbackTarget(version)}>
                        <Undo2 className="size-4" aria-hidden />
                        {t("versions.action.rollback")}
                      </Button>
                    </>
                  ) : null}
                  {canManage ? (
                    <>
                      <Button variant="ghost" size="sm" onClick={() => setCloneTarget(version)}>
                        <Copy className="size-4" aria-hidden />
                        {t("versions.action.clone")}
                      </Button>
                      <Button variant="ghost" size="sm" onClick={() => setForkTarget(version)}>
                        <GitFork className="size-4" aria-hidden />
                        {t("versions.action.fork")}
                      </Button>
                    </>
                  ) : null}
                </div>
              </li>
            ))}
          </ul>
        )}

        {meta && meta.last_page > 1 ? (
          <Pagination page={meta.current_page} lastPage={meta.last_page} onPageChange={setPage} />
        ) : null}
      </DialogContent>

      {/* Snapshot creation */}
      <SnapshotDialog
        open={snapshotOpen}
        onOpenChange={setSnapshotOpen}
        pending={create.isPending}
        onSubmit={async (label, force) => {
          try {
            await create.mutateAsync({ label: label || null, force });
            setSnapshotOpen(false);
          } catch {
            /* surfaced via the alert */
          }
        }}
      />

      {/* Restore / rollback confirmations */}
      <ConfirmDialog
        open={restoreTarget !== null}
        onOpenChange={(next) => !next && setRestoreTarget(null)}
        title={t("versions.restore.title")}
        description={restoreTarget ? t("versions.restore.body", { n: restoreTarget.version_number }) : undefined}
        confirmLabel={t("versions.restore.confirm")}
        confirmVariant="default"
        loading={restore.isPending}
        onConfirm={doRestore}
      />
      <ConfirmDialog
        open={rollbackTarget !== null}
        onOpenChange={(next) => !next && setRollbackTarget(null)}
        title={t("versions.rollback.title")}
        description={rollbackTarget ? t("versions.rollback.body", { n: rollbackTarget.version_number }) : undefined}
        confirmLabel={t("versions.rollback.confirm")}
        confirmVariant="default"
        loading={rollback.isPending}
        onConfirm={doRollback}
      />

      {/* Clone */}
      <LabelDialog
        open={cloneTarget !== null}
        onOpenChange={(next) => !next && setCloneTarget(null)}
        title={t("versions.clone.title")}
        body={cloneTarget ? t("versions.clone.body", { n: cloneTarget.version_number }) : ""}
        submitLabel={t("versions.clone.submit")}
        labelText={t("versions.snapshot.label")}
        placeholder={t("versions.snapshot.labelPlaceholder")}
        pending={clone.isPending}
        onSubmit={async (label) => {
          if (!cloneTarget) return;
          try {
            await clone.mutateAsync({ versionId: cloneTarget.id, label: label || null });
            setCloneTarget(null);
          } catch {
            /* surfaced via the alert */
          }
        }}
      />

      {/* Fork */}
      <ForkDialog
        open={forkTarget !== null}
        onOpenChange={(next) => !next && setForkTarget(null)}
        version={forkTarget}
        pending={fork.isPending}
        onSubmit={async (destination, label) => {
          if (!forkTarget) return;
          try {
            await fork.mutateAsync({
              versionId: forkTarget.id,
              input: { destination_course_id: destination, label: label || null },
            });
            setForkTarget(null);
          } catch {
            /* surfaced via the alert */
          }
        }}
      />

      {details !== null ? (
        <VersionDetailsDrawer
          version={details}
          open
          onOpenChange={(next) => {
            if (!next) setDetails(null);
          }}
        />
      ) : null}
    </Dialog>
  );
}

function SnapshotDialog({
  open,
  onOpenChange,
  pending,
  onSubmit,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  pending: boolean;
  onSubmit: (label: string, force: boolean) => void | Promise<void>;
}) {
  const { t } = useVersioningI18n();
  const [label, setLabel] = useState("");
  const [force, setForce] = useState(false);

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (pending) return;
        if (!next) {
          setLabel("");
          setForce(false);
        }
        onOpenChange(next);
      }}
    >
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{t("versions.snapshot.title")}</DialogTitle>
        </DialogHeader>
        <div className="flex flex-col gap-3">
          <label className="flex flex-col gap-1 text-sm">
            <span className="font-medium">{t("versions.snapshot.label")}</span>
            <Input
              value={label}
              onChange={(e) => setLabel(e.target.value)}
              placeholder={t("versions.snapshot.labelPlaceholder")}
            />
          </label>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={force} onChange={(e) => setForce(e.target.checked)} />
            <span>{t("versions.snapshot.force")}</span>
          </label>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={pending}>
            {t("versions.cancel")}
          </Button>
          <Button loading={pending} onClick={() => onSubmit(label, force)}>
            {t("versions.snapshot.submit")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function LabelDialog({
  open,
  onOpenChange,
  title,
  body,
  submitLabel,
  labelText,
  placeholder,
  pending,
  onSubmit,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  body: string;
  submitLabel: string;
  labelText: string;
  placeholder: string;
  pending: boolean;
  onSubmit: (label: string) => void | Promise<void>;
}) {
  const { t } = useVersioningI18n();
  const [label, setLabel] = useState("");

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (pending) return;
        if (!next) setLabel("");
        onOpenChange(next);
      }}
    >
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{body}</DialogDescription>
        </DialogHeader>
        <label className="flex flex-col gap-1 text-sm">
          <span className="font-medium">{labelText}</span>
          <Input value={label} onChange={(e) => setLabel(e.target.value)} placeholder={placeholder} />
        </label>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={pending}>
            {t("versions.cancel")}
          </Button>
          <Button loading={pending} onClick={() => onSubmit(label)}>
            {submitLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function ForkDialog({
  open,
  onOpenChange,
  version,
  pending,
  onSubmit,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  version: ContentVersion | null;
  pending: boolean;
  onSubmit: (destination: string, label: string) => void | Promise<void>;
}) {
  const { t } = useVersioningI18n();
  const [destination, setDestination] = useState("");
  const [label, setLabel] = useState("");

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (pending) return;
        if (!next) {
          setDestination("");
          setLabel("");
        }
        onOpenChange(next);
      }}
    >
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{t("versions.fork.title")}</DialogTitle>
          <DialogDescription>
            {version ? t("versions.fork.body", { n: version.version_number }) : ""}
          </DialogDescription>
        </DialogHeader>
        <div className="flex flex-col gap-3">
          <label className="flex flex-col gap-1 text-sm">
            <span className="font-medium">{t("versions.fork.destination")}</span>
            <Input
              value={destination}
              onChange={(e) => setDestination(e.target.value)}
              placeholder={t("versions.fork.destinationPlaceholder")}
            />
          </label>
          <label className="flex flex-col gap-1 text-sm">
            <span className="font-medium">{t("versions.snapshot.label")}</span>
            <Input value={label} onChange={(e) => setLabel(e.target.value)} placeholder={t("versions.snapshot.labelPlaceholder")} />
          </label>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={pending}>
            {t("versions.cancel")}
          </Button>
          <Button loading={pending} disabled={destination.trim() === ""} onClick={() => onSubmit(destination.trim(), label)}>
            {t("versions.fork.submit")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
