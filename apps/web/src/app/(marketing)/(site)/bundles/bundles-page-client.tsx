"use client";

import { useState } from "react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useBundles } from "@/lib/commerce/hooks";
import { QueryState } from "@/components/student/query-state";
import { BundleCard } from "@/components/commerce/bundle-card";
import { Pagination } from "@/components/ui/pagination";
import { EmptyState } from "@/components/states/empty-state";

/** Public bundle catalogue. A bundle grants several courses in one purchase. */
export function BundlesPageClient() {
  const { t } = useI18n();
  const [page, setPage] = useState(1);
  const query = useBundles(page);

  return (
    <div className="space-y-8">
      <header className="text-center">
        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-copper">
          {t("commerce.bundles.title")}
        </p>
        <h1 className="mt-3 font-serif text-4xl font-semibold tracking-tight sm:text-5xl">
          {t("commerce.bundles.title")}
        </h1>
        <p className="mt-3 text-muted-foreground">{t("commerce.bundles.subtitle")}</p>
      </header>

      <QueryState
        query={query}
        isEmpty={(d) => d.data.length === 0}
        empty={<EmptyState title={t("commerce.bundles.empty")} />}
      >
        {(data) => (
          <div className="space-y-6">
            <div className="stagger-in grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
              {data.data.map((b) => (
                <BundleCard key={b.id} bundle={b} />
              ))}
            </div>
            <Pagination page={data.meta.current_page} lastPage={data.meta.last_page} onPageChange={setPage} />
          </div>
        )}
      </QueryState>
    </div>
  );
}
