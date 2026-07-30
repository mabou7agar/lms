"use client";

import { useState } from "react";
import { ShoppingBag } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { formatMoney } from "@/lib/format";
import { useAdminOrders } from "@/lib/commerce/admin-hooks";
import type { AdminOrder } from "@/lib/commerce/admin";
import { AdminGuard } from "@/components/commerce/admin-guard";
import { RefundDialog } from "@/components/commerce/refund-dialog";
import { QueryState } from "@/components/student/query-state";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Pagination } from "@/components/ui/pagination";
import { EmptyState } from "@/components/states/empty-state";

const statusVariant: Record<string, "success" | "warning" | "destructive" | "secondary"> = {
  paid: "success",
  fulfilled: "success",
  pending: "warning",
  failed: "destructive",
  refunded: "secondary",
};

function OrderRow({ order, onRefund }: { order: AdminOrder; onRefund: (order: AdminOrder) => void }) {
  const { t, locale } = useI18n();
  const refundedMinor = order.refunded_minor ?? 0;
  const refundable = order.total_minor - refundedMinor > 0;

  return (
    <Card>
      <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
        <div className="min-w-0 space-y-1">
          <div className="flex items-center gap-2">
            <span className="font-semibold">{order.id}</span>
            <Badge variant={statusVariant[order.status] ?? "secondary"}>{order.status}</Badge>
          </div>
          {order.customer?.email ? (
            <p className="text-xs text-muted-foreground">
              {t("commerce.admin.customer")}: {order.customer.name ?? order.customer.email}
            </p>
          ) : null}
          {refundedMinor > 0 ? (
            <p className="text-xs text-muted-foreground tabular-nums">
              {t("commerce.admin.refund")}: −{formatMoney(refundedMinor, order.currency, locale)}
            </p>
          ) : null}
        </div>
        <div className="flex items-center gap-4">
          <span className="font-semibold tabular-nums">{formatMoney(order.total_minor, order.currency, locale)}</span>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={!refundable}
            onClick={() => onRefund(order)}
          >
            {t("commerce.admin.refund")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

function AdminOrdersView() {
  const { t } = useI18n();
  const [page, setPage] = useState(1);
  const [active, setActive] = useState<AdminOrder | null>(null);
  const query = useAdminOrders(page);

  return (
    <>
      <header className="mb-8">
        <h1 className="font-serif text-3xl font-semibold tracking-tight sm:text-4xl">
          {t("commerce.admin.orders")}
        </h1>
        <p className="mt-2 text-muted-foreground">{t("commerce.admin.ordersSubtitle")}</p>
      </header>

      <QueryState
        query={query}
        isEmpty={(d) => d.data.length === 0}
        empty={<EmptyState icon={<ShoppingBag className="size-8" />} title={t("commerce.admin.empty")} />}
      >
        {(data) => (
          <div className="space-y-4">
            <div className="stagger-in space-y-3">
              {data.data.map((order) => (
                <OrderRow key={order.id} order={order} onRefund={setActive} />
              ))}
            </div>
            <Pagination page={data.meta.current_page} lastPage={data.meta.last_page} onPageChange={setPage} />
          </div>
        )}
      </QueryState>

      {active ? <RefundDialog order={active} open={active != null} onClose={() => setActive(null)} /> : null}
    </>
  );
}

export default function AdminOrdersPage() {
  return (
    <AdminGuard>
      <AdminOrdersView />
    </AdminGuard>
  );
}
