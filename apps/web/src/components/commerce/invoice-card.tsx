"use client";

import Link from "next/link";
import { Download } from "lucide-react";
import type { Invoice } from "@/lib/commerce/billing";
import { invoicePdfUrl } from "@/lib/commerce/billing";
import { formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";

const statusVariant: Record<string, "success" | "warning" | "destructive" | "secondary"> = {
  paid: "success",
  issued: "warning",
  open: "warning",
  void: "secondary",
  refunded: "secondary",
};

export function InvoiceCard({ invoice }: { invoice: Invoice }) {
  const { t, locale } = useI18n();
  return (
    <Card className="card-hover hover:border-primary/30 hover:elevation-3">
      <CardContent className="space-y-3 p-5">
        <div className="flex items-center justify-between gap-2">
          <div className="min-w-0">
            <Link href={`/billing/${invoice.id}`} className="font-semibold hover:text-primary">
              {t("commerce.billing.invoice")} {invoice.number}
            </Link>
            {invoice.issued_at ? (
              <p className="text-xs text-muted-foreground">
                {t("commerce.billing.issued")}: {new Date(invoice.issued_at).toLocaleDateString()}
              </p>
            ) : null}
          </div>
          <Badge variant={statusVariant[invoice.status] ?? "secondary"}>{invoice.status}</Badge>
        </div>
        <div className="flex items-center justify-between gap-2">
          <p className="font-semibold tabular-nums">{formatMoney(invoice.total_minor, invoice.currency, locale)}</p>
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
      </CardContent>
    </Card>
  );
}
