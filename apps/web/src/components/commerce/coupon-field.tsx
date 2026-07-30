"use client";

import { useState } from "react";
import { errorMessage } from "@/lib/api/errors";
import { formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useValidateCoupon } from "@/lib/commerce/hooks";
import type { CouponValidation } from "@/lib/commerce/api";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";

type CouponFieldProps = {
  /** Currency for formatting the previewed discount amount. */
  currency: string;
  /** Called when a coupon validates successfully (e.g. to refresh cart totals). */
  onApplied?: (result: CouponValidation) => void;
};

/**
 * Standalone coupon entry: validates a code via `useValidateCoupon` and surfaces the
 * discount preview or a rejection reason. It does not mutate the cart itself — the caller
 * decides what to do with a valid result through `onApplied`.
 */
export function CouponField({ currency, onApplied }: CouponFieldProps) {
  const { t, locale } = useI18n();
  const validate = useValidateCoupon();
  const [code, setCode] = useState("");
  const [result, setResult] = useState<CouponValidation | null>(null);
  const [error, setError] = useState<string | null>(null);

  const onApply = () => {
    const trimmed = code.trim();
    if (trimmed.length === 0) return;
    setError(null);
    setResult(null);
    validate.mutate(trimmed, {
      onSuccess: (res) => {
        setResult(res);
        if (res.valid) onApplied?.(res);
      },
      onError: (e) => setError(errorMessage(e, t("commerce.coupon.invalid"))),
    });
  };

  const valid = result?.valid === true;
  const invalid = result != null && !result.valid;

  return (
    <div className="space-y-2">
      <label className="text-sm font-medium" htmlFor="coupon-code">
        {t("commerce.coupon.label")}
      </label>
      <div className="flex gap-2">
        <Input
          id="coupon-code"
          value={code}
          onChange={(e) => setCode(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault();
              onApply();
            }
          }}
          placeholder={t("commerce.coupon.placeholder")}
          aria-invalid={invalid || error != null}
          autoComplete="off"
        />
        <Button
          type="button"
          variant="outline"
          loading={validate.isPending}
          disabled={code.trim().length === 0}
          onClick={onApply}
        >
          {t("commerce.coupon.apply")}
        </Button>
      </div>
      {valid && result ? (
        <p className="text-sm text-success">
          {t("commerce.coupon.applied")}
          {result.discount_minor > 0
            ? ` · −${formatMoney(result.discount_minor, result.currency ?? currency, locale)}`
            : ""}
        </p>
      ) : null}
      {invalid ? (
        <p className="text-sm text-destructive">{result?.reason ?? t("commerce.coupon.invalid")}</p>
      ) : null}
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
    </div>
  );
}
