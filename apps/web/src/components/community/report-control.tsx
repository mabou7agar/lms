"use client";

import { useState } from "react";
import { Flag } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useCommunityI18n } from "@/lib/community/community-i18n";
import { REPORT_REASONS, type ReportInput, type ReportReason } from "@/lib/community/reviews-api";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { toast } from "@/components/ui/toast";

interface ReportControlProps {
  /** Performs the report; should reject on failure so the panel surfaces the error. */
  onSubmit: (input: ReportInput) => Promise<void>;
}

/**
 * Inline "report for moderation" affordance shared by reviews, questions, answers, threads and posts.
 * A small toggle button expands a reason picker (native radios — no portal) + optional note. Manages
 * its own open/submit state so callers only wire the mutation.
 */
export function ReportControl({ onSubmit }: ReportControlProps) {
  const { t } = useCommunityI18n();
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState<ReportReason>("spam");
  const [note, setNote] = useState("");
  const [pending, setPending] = useState(false);

  const submit = async () => {
    setPending(true);
    try {
      await onSubmit({ reason, note: note.trim() || null });
      toast.success(t("report.submitted"));
      setOpen(false);
      setNote("");
    } catch (e) {
      toast.error(errorMessage(e, t("common.error")));
    } finally {
      setPending(false);
    }
  };

  if (!open) {
    return (
      <Button
        type="button"
        variant="ghost"
        size="sm"
        className="h-auto gap-1 px-1.5 py-0.5 text-xs text-muted-foreground hover:text-foreground"
        onClick={() => setOpen(true)}
      >
        <Flag className="size-3" aria-hidden /> {t("report.action")}
      </Button>
    );
  }

  return (
    <div className="mt-2 rounded-lg border border-border bg-surface/40 p-3">
      <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{t("report.title")}</p>
      <RadioGroup value={reason} onValueChange={(v) => setReason(v as ReportReason)} className="mb-3 gap-1.5">
        {REPORT_REASONS.map((r) => (
          <label key={r} className="flex cursor-pointer items-center gap-2 text-sm">
            <RadioGroupItem value={r} />
            {t(`report.reason.${r}`)}
          </label>
        ))}
      </RadioGroup>
      <Textarea
        rows={2}
        value={note}
        onChange={(e) => setNote(e.target.value)}
        placeholder={t("report.notePlaceholder")}
        aria-label={t("report.note")}
        className="mb-3 text-sm"
      />
      <div className="flex items-center gap-2">
        <Button type="button" size="sm" variant="destructive" loading={pending} onClick={submit}>
          {t("report.submit")}
        </Button>
        <Button type="button" size="sm" variant="ghost" onClick={() => setOpen(false)}>
          {t("common.cancel")}
        </Button>
      </div>
    </div>
  );
}
