"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { errorMessage } from "@/lib/api/errors";
import { formatMoney } from "@/lib/format";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useAcceptContract, useCart, useCheckout, useContracts } from "@/lib/commerce/hooks";
import type { Cart } from "@/lib/commerce/api";
import { RequireAuth } from "@/lib/auth/guards";
import { CouponField } from "@/components/commerce/coupon-field";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { FormAlert } from "@/components/auth/form-alert";
import { EmptyState } from "@/components/states/empty-state";

function Summary({ cart }: { cart: Cart }) {
  const { t, locale } = useI18n();
  const tax = cart.tax_minor ?? 0;
  return (
    <div className="space-y-2 text-sm">
      {cart.items.map((i) => (
        <div key={i.id} className="flex justify-between gap-2">
          <span className="truncate text-muted-foreground">{i.title}</span>
          <span className="tabular-nums">{formatMoney(i.unit_amount_minor, cart.currency, locale)}</span>
        </div>
      ))}
      <div className="flex justify-between border-t pt-2 text-muted-foreground">
        <span>{t("commerce.checkoutBilling.subtotal")}</span>
        <span className="tabular-nums">{formatMoney(cart.subtotal_minor, cart.currency, locale)}</span>
      </div>
      {cart.discount_minor > 0 ? (
        <div className="flex justify-between text-muted-foreground">
          <span>{t("commerce.checkoutBilling.discount")}</span>
          <span className="tabular-nums">−{formatMoney(cart.discount_minor, cart.currency, locale)}</span>
        </div>
      ) : null}
      <div className="flex justify-between text-muted-foreground">
        <span>{t("commerce.checkoutBilling.tax")}</span>
        <span className="tabular-nums">{formatMoney(tax, cart.currency, locale)}</span>
      </div>
      <div className="flex justify-between border-t pt-2 text-base font-semibold">
        <span>{t("commerce.checkoutBilling.total")}</span>
        <span className="tabular-nums">{formatMoney(cart.total_minor, cart.currency, locale)}</span>
      </div>
    </div>
  );
}

function CheckoutFlow() {
  const { t } = useI18n();
  const router = useRouter();
  const qc = useQueryClient();
  const cartQuery = useCart();
  const checkout = useCheckout();
  const contracts = useContracts();
  const accept = useAcceptContract();

  const [contractId, setContractId] = useState<string | null>(null);
  const [placed, setPlaced] = useState(false);
  const [agreed, setAgreed] = useState(false);
  const [redirecting, setRedirecting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const onPlaceOrder = () => {
    setError(null);
    checkout.mutate(undefined, {
      onSuccess: (res) => {
        // Hosted MENA gateway: hand off to the provider's payment page.
        const redirectUrl = res.data.payment.redirect_url;
        if (redirectUrl) {
          setRedirecting(true);
          window.location.assign(redirectUrl);
          return;
        }
        setPlaced(true);
        setContractId(res.data.contract_id);
        if (!res.data.contract_id) router.push("/checkout/success");
      },
      onError: (e) => {
        setError(errorMessage(e, t("common.error")));
        router.push("/checkout/failed");
      },
    });
  };

  const onAccept = () => {
    if (!contractId) return;
    accept.mutate(contractId, {
      onSuccess: () => router.push("/checkout/success"),
      onError: (e) => setError(errorMessage(e, t("common.error"))),
    });
  };

  if (redirecting) {
    return (
      <Card>
        <CardContent className="p-6 text-center text-sm text-muted-foreground">
          {t("commerce.checkoutBilling.redirecting")}
        </CardContent>
      </Card>
    );
  }

  // Step 2: contract acceptance after the order is placed.
  if (placed && contractId) {
    const contract = (contracts.data ?? []).find((c) => c.id === contractId);
    return (
      <Card>
        <CardHeader>
          <CardTitle>{contract?.template?.title ?? t("commerce.checkout.terms")}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {error ? <FormAlert>{error}</FormAlert> : null}
          <div className="max-h-72 overflow-y-auto whitespace-pre-line rounded-md border bg-muted/30 p-4 text-sm text-muted-foreground">
            {contract?.template?.body ?? "…"}
          </div>
          <label className="flex items-center gap-2 text-sm">
            <Checkbox checked={agreed} onChange={(e) => setAgreed(e.target.checked)} /> {t("commerce.checkout.accept")}
          </label>
          <p className="text-xs text-muted-foreground">{t("commerce.pendingPaymentNote")}</p>
          <Button className="w-full" disabled={!agreed} loading={accept.isPending} onClick={onAccept}>
            {t("commerce.checkout.acceptContinue")}
          </Button>
        </CardContent>
      </Card>
    );
  }

  // Step 1: review the cart and place the order.
  return (
    <QueryState query={cartQuery} isEmpty={(c) => c.items.length === 0} empty={<EmptyState title={t("commerce.checkout.empty")} />}>
      {(cart) => (
        <Card>
          <CardHeader>
            <CardTitle>{t("commerce.checkout.summary")}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {error ? <FormAlert>{error}</FormAlert> : null}
            {/* State plainly who the invoice will be made out to before the order is placed. */}
            <p className="rounded-lg bg-surface/60 p-3 text-sm text-muted-foreground">
              {t("commerce.cart.invoicedTo")}:{" "}
              <span className="font-medium text-foreground">
                {cart.buyer_type === "company"
                  ? t("commerce.cart.buyForCompany")
                  : t("commerce.cart.buyForMyself")}
              </span>
            </p>
            <Summary cart={cart} />
            <CouponField currency={cart.currency} onApplied={() => qc.invalidateQueries({ queryKey: ["cart"] })} />
            <Button className="w-full" loading={checkout.isPending} onClick={onPlaceOrder}>
              {t("commerce.checkout.placeOrder")}
            </Button>
          </CardContent>
        </Card>
      )}
    </QueryState>
  );
}

export default function CheckoutPage() {
  const { t } = useI18n();
  return (
    <RequireAuth>
      <div className="mx-auto max-w-xl">
        <PageHeader title={t("commerce.checkout.title")} />
        <CheckoutFlow />
      </div>
    </RequireAuth>
  );
}
