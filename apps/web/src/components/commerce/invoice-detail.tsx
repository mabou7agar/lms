"use client";

import { Download } from "lucide-react";
import type { Invoice } from "@/lib/commerce/billing";
import { invoicePdfUrl } from "@/lib/commerce/billing";
import { formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";

const statusVariant: Record<string, "success" | "warning" | "destructive" | "secondary"> = {
  paid: "success",
  issued: "warning",
  open: "warning",
  void: "secondary",
  refunded: "secondary",
};

export function InvoiceDetail({ invoice }: { invoice: Invoice }) {
  const { t, locale } = useI18n();
  const money = (minor: number) => formatMoney(minor, invoice.currency, locale);

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader className="flex flex-row items-start justify-between gap-3 space-y-0">
          <div className="space-y-1">
            <CardTitle className="font-serif text-2xl">
              {t("commerce.billing.invoice")} {invoice.number}
            </CardTitle>
            <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
              {invoice.issued_at ? (
                <span>
                  {t("commerce.billing.issued")}: {new Date(invoice.issued_at).toLocaleDateString()}
                </span>
              ) : null}
              {invoice.paid_at ? (
                <span>
                  {t("commerce.billing.paid")}: {new Date(invoice.paid_at).toLocaleDateString()}
                </span>
              ) : null}
            </div>
          </div>
          <div className="flex flex-col items-end gap-2">
            <Badge variant={statusVariant[invoice.status] ?? "secondary"}>
              {t("commerce.billing.status")}: {invoice.status}
            </Badge>
            <a
              href={invoicePdfUrl(invoice.id)}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1.5 text-sm text-primary hover:underline"
            >
              <Download className="size-4" aria-hidden />
              {t("commerce.billing.download")}
            </a>
          </div>
        </CardHeader>

        <CardContent className="space-y-5">
          {invoice.lines?.length ? (
            <ul className="divide-y">
              {invoice.lines.map((line) => (
                <li key={line.id} className="flex items-start justify-between gap-3 py-3">
                  <div className="min-w-0">
                    <p className="truncate font-medium">{line.description}</p>
                    <p className="text-xs text-muted-foreground tabular-nums">
                      {line.quantity} × {money(line.unit_amount_minor)}
                    </p>
                  </div>
                  <span className="shrink-0 tabular-nums">{money(line.total_minor)}</span>
                </li>
              ))}
            </ul>
          ) : null}

          <dl className="ms-auto max-w-xs space-y-2 text-sm">
            <div className="flex justify-between gap-4">
              <dt className="text-muted-foreground">{t("commerce.billing.subtotal")}</dt>
              <dd className="tabular-nums">{money(invoice.subtotal_minor)}</dd>
            </div>
            <div className="flex justify-between gap-4">
              <dt className="text-muted-foreground">{t("commerce.billing.tax")}</dt>
              <dd className="tabular-nums">{money(invoice.tax_minor)}</dd>
            </div>
            <div className="flex justify-between gap-4 border-t pt-2 font-semibold">
              <dt>{t("commerce.billing.total")}</dt>
              <dd className="tabular-nums">{money(invoice.total_minor)}</dd>
            </div>
          </dl>
        </CardContent>
      </Card>
    </div>
  );
}
