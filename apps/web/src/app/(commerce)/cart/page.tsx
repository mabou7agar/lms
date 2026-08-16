"use client";

import Link from "next/link";
import { useState } from "react";
import { Trash2 } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useAddToCart, useCart, useClearCart, useRemoveCartItem } from "@/lib/commerce/hooks";
import type { Cart } from "@/lib/commerce/api";
import { RequireAuth } from "@/lib/auth/guards";
import { PageHero } from "@/components/marketing/page-hero";
import { QueryState } from "@/components/student/query-state";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Input } from "@/components/ui/input";
import { EmptyState } from "@/components/states/empty-state";
import { toast } from "@/components/ui/toast";

function CartView({ cart }: { cart: Cart }) {
  const { t, locale } = useI18n();
  const apply = useAddToCart();
  const clear = useClearCart();
  const removeItem = useRemoveCartItem();
  const [coupon, setCoupon] = useState("");
  const [clearOpen, setClearOpen] = useState(false);

  if (cart.items.length === 0) {
    return (
      <EmptyState
        title={t("commerce.cart.empty")}
        // Point at what a buyer actually browses — courses and bundles — not a generic catalogue.
        action={
          <div className="mt-2 flex flex-wrap justify-center gap-2">
            <Button asChild><Link href="/courses">{t("commerce.cart.browse")}</Link></Button>
            <Button asChild variant="outline"><Link href="/bundles">{t("commerce.bundles.title")}</Link></Button>
          </div>
        }
      />
    );
  }

  const onApplyCoupon = () =>
    apply.mutate(
      { product: cart.items[0].product_id, coupon_code: coupon.trim() },
      { onSuccess: () => toast.success(t("commerce.cart.couponApplied")), onError: (e) => toast.error(errorMessage(e, t("common.error"))) },
    );

  return (
    <div className="grid gap-6 lg:grid-cols-3">
      <div className="space-y-3 lg:col-span-2">
        {cart.items.map((item) => (
          <Card key={item.id}>
            <CardContent className="flex items-center justify-between gap-3 p-4">
              <div className="min-w-0">
                <p className="truncate font-medium">{item.title}</p>
                <p className="text-sm text-muted-foreground">{formatMoney(item.unit_amount_minor, cart.currency, locale)}</p>
              </div>
              <Button
                variant="ghost"
                size="sm"
                loading={removeItem.isPending && removeItem.variables === item.product_id}
                onClick={() =>
                  removeItem.mutate(item.product_id, {
                    onError: (e) => toast.error(errorMessage(e, t("common.error"))),
                  })
                }
              >
                <Trash2 className="size-4" aria-hidden /> {t("commerce.cart.remove")}
              </Button>
            </CardContent>
          </Card>
        ))}
        <div className="flex gap-2">
          <Input placeholder={t("commerce.cart.coupon")} value={coupon} onChange={(e) => setCoupon(e.target.value)} />
          <Button variant="outline" loading={apply.isPending} disabled={!coupon.trim()} onClick={onApplyCoupon}>
            {t("commerce.cart.applyCoupon")}
          </Button>
        </div>
      </div>

      <Card className="lg:sticky lg:top-20 lg:self-start">
        <CardContent className="space-y-3 p-5">
          <div className="flex justify-between text-sm">
            <span className="text-muted-foreground">{t("commerce.cart.subtotal")}</span>
            <span className="tabular-nums">{formatMoney(cart.subtotal_minor, cart.currency, locale)}</span>
          </div>
          {cart.discount_minor > 0 ? (
            <div className="flex justify-between text-sm text-success">
              <span>{t("commerce.cart.discount")}{cart.coupon ? ` (${cart.coupon})` : ""}</span>
              <span className="tabular-nums">-{formatMoney(cart.discount_minor, cart.currency, locale)}</span>
            </div>
          ) : null}
          <div className="flex justify-between border-t pt-3 font-semibold">
            <span>{t("commerce.cart.total")}</span>
            <span className="tabular-nums">{formatMoney(cart.total_minor, cart.currency, locale)}</span>
          </div>
          <Button asChild className="w-full"><Link href="/checkout">{t("commerce.cart.checkout")}</Link></Button>
          <Button variant="ghost" className="w-full" loading={clear.isPending} onClick={() => setClearOpen(true)}>
            {t("commerce.cart.clear")}
          </Button>
        </CardContent>
      </Card>

      <ConfirmDialog
        open={clearOpen}
        onOpenChange={setClearOpen}
        title={t("commerce.cart.clearConfirmTitle")}
        description={t("commerce.cart.clearConfirmBody")}
        confirmLabel={t("commerce.cart.clear")}
        loading={clear.isPending}
        onConfirm={() =>
          clear.mutate(undefined, {
            onSuccess: () => {
              toast.success(t("commerce.cart.cleared"));
              setClearOpen(false);
            },
            onError: (e) => {
              toast.error(errorMessage(e, t("common.error")));
              setClearOpen(false);
            },
          })
        }
      />
    </div>
  );
}

export default function CartPage() {
  const { t } = useI18n();
  const query = useCart();
  return (
    <RequireAuth>
      <PageHero page="cart" />
      <QueryState query={query}>{(cart) => <CartView cart={cart} />}</QueryState>
    </RequireAuth>
  );
}
