"use client";

import { AlertTriangle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";
import { useBuilder } from "@/lib/authoring/builder-store";

/**
 * Non-destructive optimistic-concurrency banner. Shown after a mutation is rejected with HTTP 409
 * (someone else saved newer state). It never overwrites the server's newer state — the failed edit
 * was already rolled back — and the user's unsaved input in the editors is kept. Offers "Reload
 * latest" (refetch the tree) and "Keep editing" (dismiss).
 */
export function ConflictBanner() {
  const { t } = useAuthoringI18n();
  const { conflict, reloadAfterConflict, dismissConflict } = useBuilder();

  if (!conflict) return null;

  return (
    <div
      role="alert"
      className="flex flex-col gap-2 border-b border-warning/40 bg-warning/10 px-4 py-3 text-sm sm:flex-row sm:items-center sm:gap-3"
    >
      <AlertTriangle className="size-5 shrink-0 text-warning" aria-hidden />
      <div className="min-w-0 flex-1">
        <p className="font-medium">{t("conflict.title")}</p>
        <p className="text-muted-foreground">{t("conflict.body")}</p>
      </div>
      <div className="flex shrink-0 gap-2">
        <Button size="sm" variant="outline" onClick={dismissConflict}>
          {t("conflict.dismiss")}
        </Button>
        <Button size="sm" onClick={reloadAfterConflict}>
          {t("conflict.reload")}
        </Button>
      </div>
    </div>
  );
}
