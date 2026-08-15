"use client";

import { use, useMemo } from "react";
import Link from "next/link";
import { ArrowLeft } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useConvertLead, useLeads } from "@/lib/crm/hooks";
import { errorMessage } from "@/lib/api/errors";
import { formatMoney } from "@/lib/format";
import { toast } from "@/components/ui/toast";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { SectionCard } from "@/components/org/section-card";
import { LeadStatusBadge } from "@/components/crm/lead-status-badge";
import { UnavailablePanel } from "@/components/crm/unavailable-panel";
import { EmptyState } from "@/components/states/empty-state";
import { Button } from "@/components/ui/button";

export default function LeadDetailsPage({ params }: { params: Promise<{ public_id: string }> }) {
  const { public_id } = use(params);
  const { t, locale } = useI18n();
  // No GET /leads/{lead} endpoint exists; resolve the lead from a wide list query by public_id.
  const query = useLeads({ per_page: 100 });
  const lead = useMemo(() => (query.data?.data ?? []).find((l) => l.id === public_id), [query.data, public_id]);
  const convert = useConvertLead();

  const onConvert = () => {
    convert.mutate(public_id, {
      onSuccess: () => toast.success(t("crm.details.convertSuccess")),
      onError: (err) => toast.error(errorMessage(err, t("crm.details.convertError"))),
    });
  };

  return (
    <div className="space-y-6">
      <Button asChild variant="ghost" size="sm" className="w-fit">
        <Link href="/crm/leads">
          <ArrowLeft className="size-4" aria-hidden /> {t("crm.details.back")}
        </Link>
      </Button>

      <QueryState query={query}>
        {() =>
          !lead ? (
            <EmptyState title={t("crm.details.notFound")} />
          ) : (
            <div className="space-y-6">
              <PageHeader
                title={lead.name}
                subtitle={lead.email ?? lead.phone ?? undefined}
                action={<LeadStatusBadge status={lead.status} />}
              />

              <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                  <SectionCard title={t("crm.details.profile")}>
                    <dl className="grid gap-3 sm:grid-cols-2">
                      <div>
                        <dt className="text-xs text-muted-foreground">{t("crm.details.status")}</dt>
                        <dd className="text-sm font-medium">{t(`crm.leadStatus.${lead.status}`)}</dd>
                      </div>
                      <div>
                        <dt className="text-xs text-muted-foreground">{t("crm.details.source")}</dt>
                        <dd className="text-sm font-medium">{lead.source ?? "—"}</dd>
                      </div>
                      <div>
                        <dt className="text-xs text-muted-foreground">{t("crm.details.value")}</dt>
                        <dd className="text-sm font-medium">
                          {lead.value_minor != null ? formatMoney(lead.value_minor, lead.currency ?? "USD", locale) : "—"}
                        </dd>
                      </div>
                      <div>
                        <dt className="text-xs text-muted-foreground">{t("crm.details.created")}</dt>
                        <dd className="text-sm font-medium">
                          {lead.created_at ? new Date(lead.created_at).toLocaleDateString() : "—"}
                        </dd>
                      </div>
                    </dl>
                  </SectionCard>

                  <SectionCard title={t("crm.details.timeline")}>
                    <UnavailablePanel />
                  </SectionCard>
                  <div className="grid gap-6 sm:grid-cols-2">
                    <SectionCard title={t("crm.details.activities")}>
                      <UnavailablePanel />
                    </SectionCard>
                    <SectionCard title={t("crm.details.notes")}>
                      <UnavailablePanel />
                    </SectionCard>
                  </div>
                  <SectionCard title={t("crm.details.tasks")}>
                    <UnavailablePanel />
                  </SectionCard>
                </div>

                <div className="lg:col-span-1">
                  <SectionCard title={t("crm.details.convert")}>
                    <Button
                      className="w-full"
                      onClick={onConvert}
                      disabled={convert.isPending || lead.status === "converted"}
                    >
                      {convert.isPending ? t("crm.details.converting") : t("crm.details.convert")}
                    </Button>
                    {lead.status === "converted" ? (
                      <p className="mt-2 text-xs text-muted-foreground">{t("crm.details.alreadyConverted")}</p>
                    ) : null}
                  </SectionCard>
                </div>
              </div>
            </div>
          )
        }
      </QueryState>
    </div>
  );
}
