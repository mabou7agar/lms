"use client";

import { useState } from "react";
import { Ticket } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useCoupons } from "@/lib/commerce/coupons-hooks";
import type { Coupon } from "@/lib/commerce/coupons";
import { AdminGuard } from "@/components/commerce/admin-guard";
import { CouponForm } from "@/components/commerce/coupon-form";
import { CouponRow } from "@/components/commerce/coupon-row";
import { QueryState } from "@/components/student/query-state";
import { Button } from "@/components/ui/button";
import { Pagination } from "@/components/ui/pagination";
import { EmptyState } from "@/components/states/empty-state";

/** Form target: `"new"` opens a blank create form, a `Coupon` opens its edit form, `null` closes it. */
type FormTarget = Coupon | "new" | null;

function CouponsView() {
  const { t } = useI18n();
  const [page, setPage] = useState(1);
  const [target, setTarget] = useState<FormTarget>(null);
  const query = useCoupons(page);

  return (
    <>
      <header className="mb-8 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="font-serif text-3xl font-semibold tracking-tight sm:text-4xl">
            {t("commerce.coupons.title")}
          </h1>
          <p className="mt-2 text-muted-foreground">{t("commerce.coupons.subtitle")}</p>
        </div>
        {target == null ? (
          <Button type="button" onClick={() => setTarget("new")}>
            {t("commerce.coupons.create")}
          </Button>
        ) : null}
      </header>

      {target != null ? (
        <div className="mb-6">
          <CouponForm coupon={target === "new" ? null : target} onDone={() => setTarget(null)} />
        </div>
      ) : null}

      <QueryState
        query={query}
        isEmpty={(d) => d.data.length === 0}
        empty={<EmptyState icon={<Ticket className="size-8" />} title={t("commerce.coupons.empty")} />}
      >
        {(data) => (
          <div className="space-y-4">
            <div className="stagger-in space-y-3">
              {data.data.map((coupon) => (
                <CouponRow key={coupon.id} coupon={coupon} onEdit={setTarget} />
              ))}
            </div>
            <Pagination page={data.meta.current_page} lastPage={data.meta.last_page} onPageChange={setPage} />
          </div>
        )}
      </QueryState>
    </>
  );
}

export default function CouponsPage() {
  return (
    <AdminGuard>
      <CouponsView />
    </AdminGuard>
  );
}
