"use client";

import { useId, useState } from "react";
import type { Coupon, CouponInput, CouponScope, CouponType } from "@/lib/commerce/coupons";
import { useCreateCoupon, useUpdateCoupon } from "@/lib/commerce/coupons-hooks";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { FormAlert } from "@/components/auth/form-alert";

/** Minor units per major unit for the currencies this store transacts in (all 2-decimal). */
const MINOR_PER_MAJOR = 100;

const COUPON_TYPES: CouponType[] = ["percentage", "fixed"];
const COUPON_SCOPES: CouponScope[] = ["all", "products"];

const SELECT_CLASS =
  "flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

type CouponFormProps = {
  /** Existing coupon to edit; omit / null to create a new one. */
  coupon?: Coupon | null;
  /** Called after a successful create/update, or when the admin cancels. */
  onDone: () => void;
};

/** The stored `value` is minor units for `fixed` coupons — show it in major units in the field. */
function initialValue(coupon: Coupon | null | undefined): string {
  if (!coupon) return "";
  return coupon.type === "fixed" ? String(coupon.value / MINOR_PER_MAJOR) : String(coupon.value);
}

/**
 * Create / edit form for a coupon. The admin fills the discount mechanic, value, scope, validity
 * window and redemption rules; on submit a `fixed` value is converted from major to integer minor
 * units and the payload is sent to the server, which is the sole authority on validity. The
 * client-side checks are UX affordances only, never a security boundary.
 */
export function CouponForm({ coupon, onDone }: CouponFormProps) {
  const { t } = useI18n();
  const isEdit = coupon != null;
  const create = useCreateCoupon();
  const update = useUpdateCoupon();
  const fieldId = useId();

  const [code, setCode] = useState(coupon?.code ?? "");
  const [type, setType] = useState<CouponType>(coupon?.type ?? "percentage");
  const [value, setValue] = useState(initialValue(coupon));
  const [currency, setCurrency] = useState(coupon?.currency ?? "USD");
  const [scope, setScope] = useState<CouponScope>(coupon?.scope ?? "all");
  const [startsAt, setStartsAt] = useState(coupon?.starts_at?.slice(0, 10) ?? "");
  const [endsAt, setEndsAt] = useState(coupon?.ends_at?.slice(0, 10) ?? "");
  const [perUserLimit, setPerUserLimit] = useState(
    coupon?.per_user_limit != null ? String(coupon.per_user_limit) : "",
  );
  const [firstOrderOnly, setFirstOrderOnly] = useState(coupon?.first_order_only ?? false);
  const [isActive, setIsActive] = useState(coupon?.is_active ?? true);
  const [error, setError] = useState<string | null>(null);

  const pending = create.isPending || update.isPending;

  const parsedValue = Number.parseFloat(value);
  const hasValue = value.trim().length > 0 && Number.isFinite(parsedValue) && parsedValue > 0;
  const canSubmit = code.trim().length > 0 && hasValue && !pending;

  const onSubmit = () => {
    if (!canSubmit) return;
    setError(null);

    const limit = perUserLimit.trim();
    const input: CouponInput = {
      code: code.trim(),
      type,
      value: type === "fixed" ? Math.round(parsedValue * MINOR_PER_MAJOR) : Math.round(parsedValue),
      currency: type === "fixed" ? currency.trim() || null : null,
      scope,
      starts_at: startsAt.length > 0 ? startsAt : null,
      ends_at: endsAt.length > 0 ? endsAt : null,
      per_user_limit: limit.length > 0 ? Math.round(Number.parseFloat(limit)) : null,
      first_order_only: firstOrderOnly,
      is_active: isActive,
    };

    if (isEdit && coupon) {
      update.mutate(
        { id: coupon.id, input },
        { onSuccess: onDone, onError: (e) => setError(errorMessage(e, t("common.error"))) },
      );
    } else {
      create.mutate(input, {
        onSuccess: onDone,
        onError: (e) => setError(errorMessage(e, t("common.error"))),
      });
    }
  };

  return (
    <Card>
      <CardContent className="space-y-4 p-5">
        <h2 className="font-serif text-xl font-semibold">
          {isEdit ? t("commerce.coupons.edit") : t("commerce.coupons.create")}
        </h2>

        {error ? <FormAlert>{error}</FormAlert> : null}

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <label className="text-sm font-medium" htmlFor={`${fieldId}-code`}>
              {t("commerce.coupons.code")}
            </label>
            <Input
              id={`${fieldId}-code`}
              value={code}
              onChange={(e) => setCode(e.target.value)}
              autoComplete="off"
              aria-invalid={code.trim().length === 0}
            />
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium" htmlFor={`${fieldId}-type`}>
              {t("commerce.coupons.type")}
            </label>
            <select
              id={`${fieldId}-type`}
              value={type}
              onChange={(e) => setType(e.target.value as CouponType)}
              className={SELECT_CLASS}
            >
              {COUPON_TYPES.map((option) => (
                <option key={option} value={option}>
                  {t(`commerce.coupons.${option}`)}
                </option>
              ))}
            </select>
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium" htmlFor={`${fieldId}-value`}>
              {t("commerce.coupons.value")}
            </label>
            <Input
              id={`${fieldId}-value`}
              type="number"
              inputMode="decimal"
              min="0"
              step={type === "fixed" ? "0.01" : "1"}
              value={value}
              onChange={(e) => setValue(e.target.value)}
              placeholder={type === "fixed" ? "0.00" : "0"}
              autoComplete="off"
              aria-invalid={value.trim().length > 0 && !hasValue}
            />
          </div>

          {type === "fixed" ? (
            <div className="space-y-2">
              <label className="text-sm font-medium" htmlFor={`${fieldId}-currency`}>
                {t("commerce.coupons.currency")}
              </label>
              <Input
                id={`${fieldId}-currency`}
                value={currency}
                onChange={(e) => setCurrency(e.target.value.toUpperCase())}
                maxLength={3}
                autoComplete="off"
              />
            </div>
          ) : null}

          <div className="space-y-2">
            <label className="text-sm font-medium" htmlFor={`${fieldId}-scope`}>
              {t("commerce.coupons.scope")}
            </label>
            <select
              id={`${fieldId}-scope`}
              value={scope}
              onChange={(e) => setScope(e.target.value as CouponScope)}
              className={SELECT_CLASS}
            >
              {COUPON_SCOPES.map((option) => (
                <option key={option} value={option}>
                  {t(`commerce.coupons.scopes.${option}`)}
                </option>
              ))}
            </select>
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium" htmlFor={`${fieldId}-per-user`}>
              {t("commerce.coupons.perUserLimit")}
            </label>
            <Input
              id={`${fieldId}-per-user`}
              type="number"
              inputMode="numeric"
              min="0"
              step="1"
              value={perUserLimit}
              onChange={(e) => setPerUserLimit(e.target.value)}
              placeholder={t("commerce.coupons.unlimited")}
              autoComplete="off"
            />
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium" htmlFor={`${fieldId}-starts`}>
              {t("commerce.coupons.startsAt")}
            </label>
            <Input
              id={`${fieldId}-starts`}
              type="date"
              value={startsAt}
              onChange={(e) => setStartsAt(e.target.value)}
            />
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium" htmlFor={`${fieldId}-ends`}>
              {t("commerce.coupons.endsAt")}
            </label>
            <Input
              id={`${fieldId}-ends`}
              type="date"
              value={endsAt}
              onChange={(e) => setEndsAt(e.target.value)}
            />
          </div>
        </div>

        <div className="flex flex-wrap gap-6">
          <label className="flex items-center gap-2 text-sm font-medium" htmlFor={`${fieldId}-first-order`}>
            <input
              id={`${fieldId}-first-order`}
              type="checkbox"
              className="size-4 rounded border-input"
              checked={firstOrderOnly}
              onChange={(e) => setFirstOrderOnly(e.target.checked)}
            />
            {t("commerce.coupons.firstOrderOnly")}
          </label>
          <label className="flex items-center gap-2 text-sm font-medium" htmlFor={`${fieldId}-active`}>
            <input
              id={`${fieldId}-active`}
              type="checkbox"
              className="size-4 rounded border-input"
              checked={isActive}
              onChange={(e) => setIsActive(e.target.checked)}
            />
            {t("commerce.coupons.active")}
          </label>
        </div>

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onDone} disabled={pending}>
            {t("commerce.coupons.cancel")}
          </Button>
          <Button type="button" onClick={onSubmit} disabled={!canSubmit} loading={pending}>
            {isEdit ? t("commerce.coupons.save") : t("commerce.coupons.create")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
