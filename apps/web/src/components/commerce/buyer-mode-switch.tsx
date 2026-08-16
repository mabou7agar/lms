"use client";

import Link from "next/link";
import { Building2, User } from "lucide-react";
import type { BuyerType } from "@/lib/commerce/api";
import { errorMessage } from "@/lib/api/errors";
import { useSetCartBuyer } from "@/lib/commerce/hooks";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Button } from "@/components/ui/button";
import { toast } from "@/components/ui/toast";

/**
 * Who the cart is being bought for. Switching is a server call because the rules are server-owned:
 * the organization is resolved from the caller's own membership, and a switch that would strand an
 * item already in the cart is refused. The UI just reflects the answer.
 */
export function BuyerModeSwitch({ buyerType }: { buyerType: BuyerType }) {
  const { t } = useI18n();
  const setBuyer = useSetCartBuyer();

  const switchTo = (next: BuyerType) => {
    if (next === buyerType) return;
    setBuyer.mutate(next, {
      onError: (e) => toast.error(errorMessage(e, t("common.error"))),
    });
  };

  const options: { value: BuyerType; label: string; icon: typeof User }[] = [
    { value: "individual", label: t("commerce.cart.buyForMyself"), icon: User },
    { value: "company", label: t("commerce.cart.buyForCompany"), icon: Building2 },
  ];

  return (
    <div className="rounded-2xl border border-border bg-card p-4">
      <p className="text-sm font-medium">{t("commerce.cart.buyingAs")}</p>
      <div className="mt-3 grid gap-2 sm:grid-cols-2">
        {options.map(({ value, label, icon: Icon }) => (
          <Button
            key={value}
            type="button"
            variant={value === buyerType ? "default" : "outline"}
            className="justify-start"
            loading={setBuyer.isPending && setBuyer.variables === value}
            onClick={() => switchTo(value)}
          >
            <Icon className="size-4" aria-hidden />
            {label}
          </Button>
        ))}
      </div>

      {/* Only reachable by someone with no company, since the server refuses the switch otherwise. */}
      {setBuyer.isError && buyerType === "individual" ? (
        <p className="mt-3 text-xs text-muted-foreground">
          {t("commerce.cart.needCompany")}{" "}
          <Link href="/register" className="underline underline-offset-4 hover:text-foreground">
            {t("commerce.cart.registerCompany")}
          </Link>
        </p>
      ) : null}
    </div>
  );
}
