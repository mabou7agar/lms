"use client";

import { AlertTriangle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";

/**
 * Non-destructive optimistic-concurrency banner for the nested content-blocks panel. Identical UX to
 * the curriculum builder's `ConflictBanner` (same strings, same reload/dismiss affordances), but
 * driven by props because the blocks panel owns its own store rather than the tree's `useBuilder`.
 *
 * Shown after a block mutation is rejected with HTTP 409 (someone saved newer state). The failed edit
 * was already rolled back, so the server's newer state is never overwritten. Offers "Reload latest"
 * (refetch) and "Keep editing" (dismiss).
 */
export function BlocksConflictBanner({ onReload, onDismiss }: { onReload: () => void; onDismiss: () => void }) {
  const { t } = useAuthoringI18n();

  return (
    <div
      role="alert"
      className="flex flex-col gap-2 rounded-md border border-warning/40 bg-warning/10 px-4 py-3 text-sm sm:flex-row sm:items-center sm:gap-3"
    >
      <AlertTriangle className="size-5 shrink-0 text-warning" aria-hidden />
      <div className="min-w-0 flex-1">
        <p className="font-medium">{t("conflict.title")}</p>
        <p className="text-muted-foreground">{t("conflict.body")}</p>
      </div>
      <div className="flex shrink-0 gap-2">
        <Button size="sm" variant="outline" onClick={onDismiss}>
          {t("conflict.dismiss")}
        </Button>
        <Button size="sm" onClick={onReload}>
          {t("conflict.reload")}
        </Button>
      </div>
    </div>
  );
}
