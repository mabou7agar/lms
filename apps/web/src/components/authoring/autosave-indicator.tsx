"use client";

import { Check, CloudOff, Loader2, TriangleAlert } from "lucide-react";
import { useAuthoringI18n } from "@/lib/authoring/authoring-i18n";
import type { SaveStatus } from "@/lib/authoring/types";

/** Live autosave status for the sticky toolbar. */
export function AutosaveIndicator({ status }: { status: SaveStatus }) {
  const { t } = useAuthoringI18n();

  const config: Record<SaveStatus, { Icon: typeof Check; text: string; cls: string; spin?: boolean }> = {
    idle: { Icon: Check, text: t("builder.idle"), cls: "text-muted-foreground" },
    dirty: { Icon: CloudOff, text: t("builder.dirty"), cls: "text-muted-foreground" },
    saving: { Icon: Loader2, text: t("builder.saving"), cls: "text-muted-foreground", spin: true },
    saved: { Icon: Check, text: t("builder.saved"), cls: "text-primary" },
    error: { Icon: TriangleAlert, text: t("builder.error"), cls: "text-destructive" },
  };
  const { Icon, text, cls, spin } = config[status];

  return (
    <span className={`inline-flex items-center gap-1.5 text-xs font-medium ${cls}`} role="status" aria-live="polite">
      <Icon className={`size-3.5 ${spin ? "animate-spin" : ""}`} aria-hidden />
      <span className="hidden sm:inline">{text}</span>
    </span>
  );
}
