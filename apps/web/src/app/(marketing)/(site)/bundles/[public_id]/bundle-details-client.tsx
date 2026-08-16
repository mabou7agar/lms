"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, ArrowRight, Award, Clock, Layers, ShoppingCart, Users } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useAuth } from "@/lib/auth/auth-context";
import { useAddToCart, useProduct } from "@/lib/commerce/hooks";
import {
  accessLabel,
  audienceLabels,
  certificateLabel,
  defaultPrice,
  displayPrice,
  seatLabel,
} from "@/lib/commerce/sales-format";
import { useI18n } from "@/lib/i18n/i18n-context";
import { QueryState } from "@/components/student/query-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { PriceTag } from "@/components/commerce/price-tag";
import { Reveal } from "@/components/landing/reveal";
import { toast } from "@/components/ui/toast";

/**
 * Public bundle page. Shows exactly what the purchase grants — the courses inside it, how long
 * access lasts, the certificate terms, and for a company buyer the seat rules — then sells it.
 *
 * Company checkout itself is not built yet, so a company-eligible bundle says setup continues at
 * checkout rather than implying a flow that does not exist.
 */
export function BundleDetailsClient() {
  const { t, locale } = useI18n();
  const params = useParams<{ public_id: string }>();
  const publicId = params.public_id;
  const router = useRouter();
  const { status } = useAuth();
  const query = useProduct(publicId);
  const add = useAddToCart();
  const authed = status === "authenticated";

  const onAdd = () => {
    if (!authed) {
      router.push(`/login?redirect=/bundles/${publicId}`);
      return;
    }
    add.mutate(
      { product: publicId },
      {
        onSuccess: () =>
          toast.success(t("commerce.bundles.added"), {
            action: { label: t("commerce.bundles.goToCart"), onClick: () => router.push("/cart") },
          }),
        onError: (e) => toast.error(errorMessage(e, t("common.error"))),
      },
    );
  };

  return (
    <div className="pb-10">
      <Button asChild variant="ghost" size="sm" className="mb-4">
        <Link href="/bundles">
          <ArrowLeft className="size-4 rtl:rotate-180" aria-hidden /> {t("commerce.bundles.back")}
        </Link>
      </Button>

      <QueryState query={query}>
        {(bundle) => {
          const price = displayPrice(defaultPrice(bundle), locale);
          const access = accessLabel(bundle.access, locale);
          const certificate = certificateLabel(bundle.certificate, locale);
          const seats = seatLabel(bundle.seats, locale);
          const audiences = audienceLabels(bundle.audience, locale);
          const courses = bundle.courses ?? [];
          const forCompany = bundle.audience === "company" || bundle.audience === "both";

          return (
            <div className="grid gap-10 lg:grid-cols-[1.4fr_0.6fr]">
              <div className="space-y-10">
                <header>
                  <div className="flex items-center gap-2 text-copper">
                    <Layers className="size-4" aria-hidden />
                    <span className="text-xs font-semibold uppercase tracking-[0.16em]">
                      {t("commerce.bundles.title")}
                    </span>
                  </div>
                  <h1 className="mt-3 font-serif text-3xl font-semibold tracking-tight sm:text-4xl">
                    {bundle.title}
                  </h1>
                  {bundle.description ? (
                    <p className="mt-3 max-w-2xl leading-relaxed text-muted-foreground">{bundle.description}</p>
                  ) : null}
                  {audiences.length > 0 ? (
                    <div className="mt-4 flex flex-wrap gap-1.5">
                      {audiences.map((label) => (
                        <Badge key={label} variant="secondary">{label}</Badge>
                      ))}
                    </div>
                  ) : null}
                </header>

                {courses.length > 0 ? (
                  <Reveal as="section">
                    <h2 className="font-serif text-2xl font-semibold">{t("commerce.bundles.included")}</h2>
                    <div className="mt-6 divide-y divide-border/60 rounded-2xl border border-border/70">
                      {courses.map((c) => (
                        <Link
                          key={c.id}
                          href={`/courses/${c.id}`}
                          className="flex items-center justify-between gap-4 p-4 transition-colors hover:bg-surface/60"
                        >
                          <span className="font-medium">{c.title}</span>
                          <ArrowRight className="size-4 shrink-0 text-muted-foreground rtl:rotate-180" aria-hidden />
                        </Link>
                      ))}
                    </div>
                  </Reveal>
                ) : null}
              </div>

              {/* BUY BOX */}
              <aside className="lg:sticky lg:top-24 lg:self-start">
                <div className="space-y-4 rounded-2xl border border-border/70 bg-card p-6 shadow-sm">
                  <PriceTag price={price} size="lg" />

                  <Button className="w-full shine relative overflow-hidden" size="lg" loading={add.isPending} onClick={onAdd}>
                    <ShoppingCart className="size-4" aria-hidden />
                    {authed ? t("commerce.bundles.addToCart") : t("commerce.bundles.signInToBuy")}
                  </Button>

                  <ul className="space-y-2 text-sm text-muted-foreground">
                    {courses.length > 0 ? (
                      <li className="flex items-center gap-2">
                        <Layers className="size-4 text-copper" aria-hidden />
                        {courses.length} {t("commerce.bundles.includedCount")}
                      </li>
                    ) : null}
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
                    {seats ? (
                      <li className="flex items-center gap-2">
                        <Users className="size-4 text-copper" aria-hidden />
                        {seats}
                      </li>
                    ) : null}
                  </ul>

                  {forCompany ? (
                    <p className="rounded-lg bg-surface/60 p-3 text-xs leading-relaxed text-muted-foreground">
                      {t("commerce.bundles.companyNote")}
                    </p>
                  ) : null}
                </div>
              </aside>
            </div>
          );
        }}
      </QueryState>
    </div>
  );
}
