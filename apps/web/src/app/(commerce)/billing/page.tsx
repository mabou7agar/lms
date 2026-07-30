"use client";

import { useState } from "react";
import { Receipt } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useInvoices } from "@/lib/commerce/billing-hooks";
import { RequireAuth } from "@/lib/auth/guards";
import { QueryState } from "@/components/student/query-state";
import { InvoiceCard } from "@/components/commerce/invoice-card";
import { Pagination } from "@/components/ui/pagination";
import { EmptyState } from "@/components/states/empty-state";

export default function BillingPage() {
  const { t } = useI18n();
  const [page, setPage] = useState(1);
  const query = useInvoices(page);
  return (
    <RequireAuth>
      <header className="mb-8">
        <h1 className="font-serif text-3xl font-semibold tracking-tight sm:text-4xl">{t("commerce.billing.title")}</h1>
        <p className="mt-2 text-muted-foreground">{t("commerce.billing.subtitle")}</p>
      </header>
      <QueryState
        query={query}
        isEmpty={(d) => d.data.length === 0}
        empty={<EmptyState icon={<Receipt className="size-8" />} title={t("commerce.billing.empty")} />}
      >
        {(data) => (
          <div className="space-y-4">
            <div className="stagger-in grid gap-4 sm:grid-cols-2">
              {data.data.map((inv) => (
                <InvoiceCard key={inv.id} invoice={inv} />
              ))}
            </div>
            <Pagination page={data.meta.current_page} lastPage={data.meta.last_page} onPageChange={setPage} />
          </div>
        )}
      </QueryState>
    </RequireAuth>
  );
}
