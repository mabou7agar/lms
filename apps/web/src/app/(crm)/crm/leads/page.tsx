"use client";

import { useEffect, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Search, Plus } from "lucide-react";
import { applyApiFieldErrors, errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useCreateLead, useLeads } from "@/lib/crm/hooks";
import type { CreateLeadInput, LeadStatus } from "@/lib/crm/api";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { EmptyState } from "@/components/states/empty-state";
import { LeadRow } from "@/components/crm/lead-row";
import { Field } from "@/components/auth/field";
import { FormAlert } from "@/components/auth/form-alert";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Pagination } from "@/components/ui/pagination";

const STATUSES: LeadStatus[] = ["new", "working", "qualified", "converted", "lost"];
const controlClass =
  "flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2";

type FormValues = { name: string; email?: string; phone?: string; source?: string; value?: string; currency?: string };

function CreateLeadForm() {
  const { t } = useI18n();
  const create = useCreateLead();
  const [formError, setFormError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  const schema = z.object({
    name: z.string().min(1, t("crm.error")).max(255),
    email: z.string().email(t("crm.error")).optional().or(z.literal("")),
    phone: z.string().max(32).optional(),
    source: z.string().max(64).optional(),
    value: z.string().optional(),
    currency: z.string().optional(),
  });

  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { name: "", email: "", phone: "", source: "", value: "", currency: "" } });

  const onSubmit = handleSubmit((v) => {
    setFormError(null);
    setDone(false);
    const payload: CreateLeadInput = {
      name: v.name,
      email: v.email?.trim() || undefined,
      phone: v.phone?.trim() || undefined,
      source: v.source?.trim() || undefined,
      value_minor: v.value && v.value.trim() ? Math.round(Number(v.value) * 100) : undefined,
      currency: v.currency?.trim() ? v.currency.trim().toUpperCase() : undefined,
    };
    create.mutate(payload, {
      onSuccess: () => {
        setDone(true);
        reset({ name: "", email: "", phone: "", source: "", value: "", currency: "" });
      },
      onError: (err) => {
        if (!applyApiFieldErrors(err, setError)) setFormError(errorMessage(err, t("crm.error")));
      },
    });
  });

  return (
    <form onSubmit={onSubmit} className="space-y-4" noValidate>
      {done ? <FormAlert variant="success">{t("crm.leads.success")}</FormAlert> : null}
      {formError ? <FormAlert>{formError}</FormAlert> : null}
      <Field id="lead-name" label={t("crm.leads.name")} error={errors.name?.message}>
        <Input id="lead-name" placeholder={t("crm.leads.namePlaceholder")} {...register("name")} />
      </Field>
      <Field id="lead-email" label={t("crm.leads.email")} error={errors.email?.message}>
        <Input id="lead-email" type="email" placeholder={t("crm.leads.emailPlaceholder")} {...register("email")} />
      </Field>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field id="lead-phone" label={t("crm.leads.phone")} error={errors.phone?.message}>
          <Input id="lead-phone" {...register("phone")} />
        </Field>
        <Field id="lead-source" label={t("crm.leads.source")} error={errors.source?.message}>
          <Input id="lead-source" placeholder={t("crm.leads.sourcePlaceholder")} {...register("source")} />
        </Field>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field id="lead-value" label={t("crm.leads.value")} error={errors.value?.message}>
          <Input id="lead-value" inputMode="decimal" {...register("value")} />
        </Field>
        <Field id="lead-currency" label={t("crm.leads.currency")} error={errors.currency?.message}>
          <Input id="lead-currency" maxLength={3} placeholder="USD" {...register("currency")} />
        </Field>
      </div>
      <Button type="submit" disabled={create.isPending} className="w-full">
        <Plus className="size-4" aria-hidden />
        {create.isPending ? t("crm.leads.submitting") : t("crm.leads.submit")}
      </Button>
    </form>
  );
}

export default function LeadsPage() {
  const { t } = useI18n();
  const [q, setQ] = useState("");
  const [debouncedQ, setDebouncedQ] = useState("");
  const [status, setStatus] = useState<LeadStatus | "">("");
  const [page, setPage] = useState(1);

  useEffect(() => {
    const id = setTimeout(() => setDebouncedQ(q), 300);
    return () => clearTimeout(id);
  }, [q]);

  // Reset to page 1 when the search/filter changes — during render (React's "adjust state while
  // rendering" pattern) rather than in an effect, avoiding the extra cascading render.
  const filterKey = `${debouncedQ}|${status}`;
  const [prevFilterKey, setPrevFilterKey] = useState(filterKey);
  if (filterKey !== prevFilterKey) {
    setPrevFilterKey(filterKey);
    setPage(1);
  }

  const query = useLeads({ q: debouncedQ || undefined, status: status || undefined, page, per_page: 15 });

  return (
    <div className="space-y-6">
      <PageHeader eyebrow="PIPELINE" icon="Contact" title={t("crm.leads.title")} subtitle={t("crm.leads.subtitle")} />

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <div className="grid gap-3 rounded-lg border bg-card p-4 sm:grid-cols-3">
            <div className="relative sm:col-span-2">
              <Search className="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted-foreground" aria-hidden />
              <Input
                className="ps-9"
                placeholder={t("crm.leads.search")}
                value={q}
                onChange={(e) => setQ(e.target.value)}
                aria-label={t("crm.leads.search")}
              />
            </div>
            <select
              className={controlClass}
              value={status}
              onChange={(e) => setStatus(e.target.value as LeadStatus | "")}
              aria-label={t("crm.leads.allStatuses")}
            >
              <option value="">{t("crm.leads.allStatuses")}</option>
              {STATUSES.map((s) => (
                <option key={s} value={s}>
                  {t(`crm.leadStatus.${s}`)}
                </option>
              ))}
            </select>
          </div>

          <QueryState
            query={query}
            isEmpty={(d) => d.data.length === 0}
            empty={<EmptyState icon={<Search className="size-8" />} title={t("crm.leads.empty")} />}
          >
            {(data) => (
              <div className="space-y-3">
                {data.data.map((l) => (
                  <LeadRow key={l.id} lead={l} />
                ))}
                {data.meta.last_page > 1 ? (
                  <Pagination page={page} lastPage={data.meta.last_page} onPageChange={setPage} />
                ) : null}
              </div>
            )}
          </QueryState>
        </div>

        <div className="lg:col-span-1">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">{t("crm.leads.newLead")}</CardTitle>
            </CardHeader>
            <CardContent>
              <CreateLeadForm />
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
