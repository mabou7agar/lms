"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { Award, Clock, Layers, ShoppingCart } from "lucide-react";
import type { CoursePurchase } from "@/lib/catalog/api";
import { errorMessage } from "@/lib/api/errors";
import { useAuth } from "@/lib/auth/auth-context";
import { useAddToCart } from "@/lib/commerce/hooks";
import { accessLabel, certificateLabel, coursePurchasePrice } from "@/lib/commerce/sales-format";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Button } from "@/components/ui/button";
import { PriceTag } from "@/components/commerce/price-tag";
import { toast } from "@/components/ui/toast";

/**
 * The buy box on a public course page. Every public course is sold, so this replaces the old
 * enrol call entirely: the only way into a course is a purchase (or a company grant), and the
 * enrol endpoint refuses a paid course anyway.
 *
 * A guest is sent to sign-in with a redirect back to this course, so the intent survives the round
 * trip and the buy button is still waiting on return.
 */
export function CoursePurchasePanel({
  courseId,
  purchase,
  compact = false,
}: {
  courseId: string;
  purchase: CoursePurchase | null | undefined;
  compact?: boolean;
}) {
  const { t, locale } = useI18n();
  const router = useRouter();
  const { status } = useAuth();
  const add = useAddToCart();
  const authed = status === "authenticated";

  const sellable = purchase?.purchasable === true ? purchase : null;
  const price = coursePurchasePrice(purchase, locale);

  // Nothing sells this course yet. Say so plainly rather than offering an action that would fail.
  if (!sellable) {
    return (
      <div className={compact ? "" : "space-y-2"}>
        <Button className="w-full" size={compact ? "default" : "lg"} disabled>
          {t("catalog.course.unavailable")}
        </Button>
        {compact ? null : (
          <p className="px-1 text-center text-xs text-muted-foreground">
            {t("catalog.course.unavailableHint")}
          </p>
        )}
      </div>
    );
  }

  const onAdd = () => {
    if (!authed) {
      router.push(`/login?redirect=/courses/${courseId}`);
      return;
    }
    add.mutate(
      { product: sellable.product_id },
      {
        onSuccess: () =>
          toast.success(t("catalog.course.addedToCart"), {
            action: { label: t("catalog.course.goToCart"), onClick: () => router.push("/cart") },
          }),
        onError: (e) => toast.error(errorMessage(e, t("common.error"))),
      },
    );
  };

  const cta = (
    <Button
      className="w-full shine relative overflow-hidden"
      size={compact ? "default" : "lg"}
      loading={add.isPending}
      onClick={onAdd}
    >
      <ShoppingCart className="size-4" aria-hidden />
      {authed ? t("catalog.course.addToCart") : t("catalog.course.signInToBuy")}
    </Button>
  );

  // The mobile sticky bar only has room for the price and the button.
  if (compact) {
    return (
      <div className="flex items-center gap-3">
        <PriceTag price={price} size="sm" />
        <div className="ms-auto">{cta}</div>
      </div>
    );
  }

  const access = accessLabel(sellable.access, locale);
  const certificate = certificateLabel(sellable.certificate, locale);

  return (
    <div className="space-y-4">
      <PriceTag price={price} size="lg" />
      {cta}

      <ul className="space-y-2 text-sm text-muted-foreground">
        {access ? (
          <li className="flex items-center gap-2">
            <Clock className="size-4 text-copper" aria-hidden />
            {access}
          </li>
        ) : null}
        {certificate ? (
          <li className="flex items-center gap-2">
            <Award className="size-4 text-copper" aria-hidden />
            {certificate}
          </li>
        ) : null}
        {sellable.included_in_bundles.length > 0 ? (
          <li className="flex items-center gap-2">
            <Layers className="size-4 text-copper" aria-hidden />
            <Link href="/bundles" className="underline underline-offset-4 hover:text-foreground">
              {t("catalog.course.alsoInBundles")}
            </Link>
          </li>
        ) : null}
      </ul>
    </div>
  );
}
