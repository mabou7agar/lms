"use client";

import type { Coupon } from "@/lib/commerce/coupons";
import { formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";

type CouponRowProps = {
  coupon: Coupon;
  /** Open the edit form for this coupon. */
  onEdit: (coupon: Coupon) => void;
};

/**
 * Admin list row for one coupon: code, discount mechanic, resolved value, scope, active state and
 * the running redemption count. Presentational — editing is delegated to the parent via `onEdit`.
 * `value` is rendered per {@link Coupon.type}: a percent for `percentage`, formatted money for `fixed`.
 */
export function CouponRow({ coupon, onEdit }: CouponRowProps) {
  const { t, locale } = useI18n();

  const valueLabel =
    coupon.type === "percentage"
      ? `${coupon.value}%`
      : formatMoney(coupon.value, coupon.currency ?? "USD", locale);
  const typeLabel = coupon.type === "percentage" ? t("commerce.coupons.percentage") : t("commerce.coupons.fixed");

  return (
    <Card>
      <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
        <div className="min-w-0 space-y-1">
          <div className="flex items-center gap-2">
            <span className="font-mono font-semibold">{coupon.code}</span>
            <Badge variant="secondary">{typeLabel}</Badge>
            <Badge variant={coupon.is_active ? "success" : "secondary"}>
              {coupon.is_active ? t("commerce.coupons.active") : t("commerce.coupons.inactive")}
            </Badge>
          </div>
          <p className="text-xs text-muted-foreground">
            {t("commerce.coupons.scope")}: {t(`commerce.coupons.scopes.${coupon.scope}`)} ·{" "}
            {t("commerce.coupons.redeemed")}: <span className="tabular-nums">{coupon.redeemed_count}</span>
          </p>
        </div>
        <div className="flex items-center gap-4">
          <span className="font-semibold tabular-nums">{valueLabel}</span>
          <Button type="button" variant="outline" size="sm" onClick={() => onEdit(coupon)}>
            {t("commerce.coupons.edit")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
