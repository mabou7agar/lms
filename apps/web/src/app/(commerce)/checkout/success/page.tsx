"use client";

import Link from "next/link";
import { CheckCircle2 } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { RequireAuth } from "@/lib/auth/guards";
import { useOrders } from "@/lib/commerce/hooks";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";

export default function CheckoutSuccessPage() {
  const { t } = useI18n();
  // The cart has been emptied by now, so read ownership from the most recent order instead.
  const orders = useOrders(1);
  const isCompanyPurchase = orders.data?.data?.[0]?.buyer_type === "company";

  return (
    <RequireAuth>
      <div className="mx-auto max-w-lg py-8">
        <Card>
          <CardContent className="flex flex-col items-center gap-4 p-8 text-center">
            <CheckCircle2 className="size-12 text-success" aria-hidden />
            <h1 className="text-2xl font-bold">{t("commerce.success.title")}</h1>
            <p className="text-muted-foreground">{t("commerce.success.body")}</p>
            <p className="text-xs text-muted-foreground">{t("commerce.pendingPaymentNote")}</p>
            {/* A company purchase is not learning the buyer does themselves — it becomes seats their
                manager assigns, so send them to the training portal rather than My Learning. */}
            {isCompanyPurchase ? (
              <>
                <p className="text-sm">{t("commerce.success.companyBody")}</p>
                <div className="flex flex-wrap justify-center gap-2">
                  <Button asChild><Link href="/manager/training">{t("commerce.success.toTraining")}</Link></Button>
                  <Button asChild variant="outline"><Link href="/orders">{t("commerce.success.toOrders")}</Link></Button>
                </div>
              </>
            ) : (
              <div className="flex flex-wrap justify-center gap-2">
                <Button asChild><Link href="/my-learning">{t("commerce.success.toLearning")}</Link></Button>
                <Button asChild variant="outline"><Link href="/orders">{t("commerce.success.toOrders")}</Link></Button>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </RequireAuth>
  );
}
