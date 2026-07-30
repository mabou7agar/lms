"use client";

import { useParams } from "next/navigation";
import { useInvoice } from "@/lib/commerce/billing-hooks";
import { RequireAuth } from "@/lib/auth/guards";
import { QueryState } from "@/components/student/query-state";
import { InvoiceDetail } from "@/components/commerce/invoice-detail";

export default function InvoiceDetailPage() {
  const params = useParams<{ id: string }>();
  const query = useInvoice(params.id ?? "");
  return (
    <RequireAuth>
      <QueryState query={query}>{(invoice) => <InvoiceDetail invoice={invoice} />}</QueryState>
    </RequireAuth>
  );
}
