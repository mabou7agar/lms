"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { CheckCircle2 } from "lucide-react";
import { applyApiFieldErrors, errorMessage } from "@/lib/api/errors";
import { track } from "@/lib/analytics/track";
import { Field } from "@/components/auth/field";
import { FormAlert } from "@/components/auth/form-alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  COMPANY_SIZES,
  REQUEST_TYPES,
  submitEnterpriseLead,
  type EnterpriseLeadUtm,
} from "@/lib/enterprise/api";
import { useEnterpriseLeadI18n } from "@/lib/enterprise/enterprise-lead-i18n";

const SELECT_CLASS =
  "flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50";

interface CapturedAttribution {
  utm: EnterpriseLeadUtm;
  gclid?: string;
  referrer?: string;
  sourcePage: string;
}

/** Reads UTM / attribution off the current URL once, client-side (lazy initializer, no effect). */
function useCapturedAttribution(): CapturedAttribution {
  const [attribution] = useState<CapturedAttribution>(() => {
    if (typeof window === "undefined") return { utm: {}, sourcePage: "/enterprise" };
    const p = new URLSearchParams(window.location.search);
    const pick = (k: string): string | undefined => p.get(k) ?? undefined;
    return {
      utm: {
        source: pick("utm_source"),
        medium: pick("utm_medium"),
        campaign: pick("utm_campaign"),
        term: pick("utm_term"),
        content: pick("utm_content"),
      },
      gclid: pick("gclid"),
      referrer: document.referrer || undefined,
      sourcePage: window.location.pathname || "/enterprise",
    };
  });

  return attribution;
}

export function EnterpriseLeadForm() {
  const { t, locale } = useEnterpriseLeadI18n();
  const attribution = useCapturedAttribution();
  const [pending, setPending] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const schema = z.object({
    name: z.string().min(1, t("form.required")),
    work_email: z.string().min(1, t("form.required")).email(t("form.invalidEmail")),
    company: z.string().min(1, t("form.required")),
    phone: z.string().optional(),
    company_size: z.string().optional(),
    country: z.string().optional(),
    request_type: z.enum(["demo", "pricing", "contact", "partnership"]),
    message: z.string().optional(),
    marketing_consent: z.boolean().optional(),
    website: z.string().optional(),
  });

  type Values = z.infer<typeof schema>;

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: "",
      work_email: "",
      company: "",
      phone: "",
      company_size: "",
      country: "",
      request_type: "demo",
      message: "",
      marketing_consent: false,
      website: "",
    },
  });

  const onSubmit = handleSubmit(async (v) => {
    setFormError(null);

    // Honeypot: a filled `website` is a bot. Silently acknowledge (never tip off the bot) and skip
    // the network call entirely.
    if (v.website && v.website.trim() !== "") {
      setSubmitted(true);
      return;
    }

    setPending(true);
    try {
      await submitEnterpriseLead({
        name: v.name,
        work_email: v.work_email,
        company: v.company,
        phone: v.phone?.trim() || undefined,
        company_size: v.company_size || undefined,
        country: v.country?.trim() || undefined,
        request_type: v.request_type,
        message: v.message?.trim() || undefined,
        source_page: attribution.sourcePage,
        utm: attribution.utm,
        gclid: attribution.gclid,
        referrer: attribution.referrer,
        locale,
        marketing_consent: v.marketing_consent ?? false,
        website: "",
      });
      // Fire the conversion event on success. No PII in the payload (redactor strips it anyway).
      track("enterprise_demo_submitted", { locale: locale as "en" | "ar", path: "/enterprise" });
      setSubmitted(true);
    } catch (err) {
      if (!applyApiFieldErrors(err, setError)) setFormError(errorMessage(err, t("form.errorGeneric")));
    } finally {
      setPending(false);
    }
  });

  if (submitted) {
    return (
      <div className="rounded-2xl border border-primary/25 bg-primary/[0.04] p-6 text-center" role="status">
        <CheckCircle2 className="mx-auto size-8 text-primary" aria-hidden />
        <h3 className="mt-3 font-serif text-lg font-semibold">{t("form.successTitle")}</h3>
        <p className="mt-1 text-sm text-muted-foreground">{t("form.successBody")}</p>
      </div>
    );
  }

  return (
    <form onSubmit={onSubmit} className="grid gap-4 sm:grid-cols-2" noValidate>
      {formError ? (
        <div className="sm:col-span-2">
          <FormAlert>{formError}</FormAlert>
        </div>
      ) : null}

      <Field id="name" label={t("form.name")} error={errors.name?.message} required>
        <Input id="name" autoComplete="name" {...register("name")} />
      </Field>
      <Field id="work_email" label={t("form.workEmail")} error={errors.work_email?.message} required>
        <Input id="work_email" type="email" autoComplete="email" {...register("work_email")} />
      </Field>
      <Field id="company" label={t("form.company")} error={errors.company?.message} required>
        <Input id="company" autoComplete="organization" {...register("company")} />
      </Field>
      <Field id="phone" label={t("form.phone")} error={errors.phone?.message}>
        <Input id="phone" type="tel" autoComplete="tel" {...register("phone")} />
      </Field>
      <Field id="company_size" label={t("form.companySize")} error={errors.company_size?.message}>
        <select id="company_size" className={SELECT_CLASS} {...register("company_size")}>
          <option value="">{t("form.companySizePlaceholder")}</option>
          {COMPANY_SIZES.map((s) => (
            <option key={s} value={s}>
              {s}
            </option>
          ))}
        </select>
      </Field>
      <Field id="country" label={t("form.country")} error={errors.country?.message}>
        <Input id="country" autoComplete="country" maxLength={2} {...register("country")} />
      </Field>
      <div className="sm:col-span-2">
        <Field id="request_type" label={t("form.requestType")} error={errors.request_type?.message} required>
          <select id="request_type" className={SELECT_CLASS} {...register("request_type")}>
            {REQUEST_TYPES.map((rt) => (
              <option key={rt} value={rt}>
                {t(`form.type.${rt}`)}
              </option>
            ))}
          </select>
        </Field>
      </div>
      <div className="sm:col-span-2">
        <Field id="message" label={t("form.message")} error={errors.message?.message}>
          <Textarea id="message" rows={4} placeholder={t("form.messagePlaceholder")} {...register("message")} />
        </Field>
      </div>

      <div className="sm:col-span-2">
        <label className="flex items-start gap-2 text-sm text-muted-foreground">
          <input type="checkbox" className="mt-1 size-4" {...register("marketing_consent")} />
          <span>{t("form.consent")}</span>
        </label>
      </div>

      {/* Honeypot — moved off-screen (not display:none, so it stays fillable by bots). */}
      <div aria-hidden className="absolute left-[-9999px] top-auto h-px w-px overflow-hidden">
        <label htmlFor="website">Website</label>
        <input id="website" type="text" tabIndex={-1} autoComplete="off" {...register("website")} />
      </div>

      <div className="sm:col-span-2">
        <Button type="submit" size="lg" className="w-full sm:w-auto" loading={pending}>
          {pending ? t("form.submitting") : t("form.submit")}
        </Button>
      </div>
    </form>
  );
}
