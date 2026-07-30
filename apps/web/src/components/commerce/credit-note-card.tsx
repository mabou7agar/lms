"use client";

import type { CreditNote } from "@/lib/commerce/admin";
import { formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";

const statusVariant: Record<string, "success" | "warning" | "destructive" | "secondary"> = {
  issued: "success",
  draft: "warning",
  void: "secondary",
};

/**
 * Admin read-only card for a single credit note. Money is server-computed integer minor units;
 * the stored magnitude is positive and rendered as a negative (credited) amount.
 */
export function CreditNoteCard({ creditNote }: { creditNote: CreditNote }) {
  const { t, locale } = useI18n();
  const money = (minor: number) => formatMoney(minor, creditNote.currency, locale);

  return (
    <Card className="card-hover hover:border-primary/30 hover:elevation-3">
      <CardContent className="space-y-3 p-5">
        <div className="flex items-center justify-between gap-2">
          <div className="min-w-0">
            <p className="font-semibold">
              {t("commerce.admin.number")} {creditNote.number}
            </p>
            {creditNote.issued_at ? (
              <p className="text-xs text-muted-foreground">
                {t("commerce.admin.date")}: {new Date(creditNote.issued_at).toLocaleDateString(locale)}
              </p>
            ) : null}
          </div>
          <Badge variant={statusVariant[creditNote.status] ?? "secondary"}>{creditNote.status}</Badge>
        </div>

        {creditNote.order_id ? (
          <p className="text-xs text-muted-foreground">
            {t("commerce.admin.order")}: <span className="font-medium">{creditNote.order_id}</span>
          </p>
        ) : null}

        {creditNote.lines?.length ? (
          <ul className="divide-y border-t">
            {creditNote.lines.map((line) => (
              <li key={line.id} className="flex items-start justify-between gap-3 py-2 text-sm">
                <span className="min-w-0 truncate text-muted-foreground">{line.description}</span>
                <span className="shrink-0 tabular-nums">−{money(line.amount_minor)}</span>
              </li>
            ))}
          </ul>
        ) : null}

        <div className="flex items-center justify-between gap-2 border-t pt-3">
          <span className="text-sm text-muted-foreground">{t("commerce.admin.amount")}</span>
          <span className="font-semibold tabular-nums text-destructive">−{money(creditNote.total_minor)}</span>
        </div>
      </CardContent>
    </Card>
  );
}
