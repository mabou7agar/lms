"use client";

import { useEffect, useRef, useState } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { CheckCircle2, GraduationCap, Sparkles, Wallet, Users } from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { track } from "@/lib/analytics/track";
import { applyApiFieldErrors, errorMessage } from "@/lib/api/errors";
import { submitEnterpriseLead, type EnterpriseLeadUtm } from "@/lib/enterprise/api";
import { Reveal } from "@/components/landing/reveal";
import { Field } from "@/components/auth/field";
import { FormAlert } from "@/components/auth/form-alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";

const SELECT_CLASS =
  "flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50";

/** The route that owns this funnel; also the analytics/source path if the URL can't be read. */
const SOURCE_PAGE = "/teach/apply";

/** Experience buckets. Keys drive the i18n label; the English label goes into the sales message. */
const EXPERIENCE_OPTIONS = ["0-1", "1-3", "3-5", "5-10", "10+"] as const;
type Experience = (typeof EXPERIENCE_OPTIONS)[number];

const EXPERIENCE_EN: Record<Experience, string> = {
  "0-1": "Less than 1 year",
  "1-3": "1-3 years",
  "3-5": "3-5 years",
  "5-10": "5-10 years",
  "10+": "More than 10 years",
};

const BENEFITS: { icon: LucideIcon; titleKey: string; bodyKey: string }[] = [
  { icon: Users, titleKey: "teach.apply.benefit1Title", bodyKey: "teach.apply.benefit1Body" },
  { icon: Sparkles, titleKey: "teach.apply.benefit2Title", bodyKey: "teach.apply.benefit2Body" },
  { icon: Wallet, titleKey: "teach.apply.benefit3Title", bodyKey: "teach.apply.benefit3Body" },
];

interface CapturedAttribution {
  utm: EnterpriseLeadUtm;
  gclid?: string;
  referrer?: string;
  sourcePage: string;
}

/** Reads UTM / attribution off the current URL once, client-side (lazy initializer, no effect). */
function useCapturedAttribution(): CapturedAttribution {
  const [attribution] = useState<CapturedAttribution>(() => {
    if (typeof window === "undefined") return { utm: {}, sourcePage: SOURCE_PAGE };
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
      sourcePage: window.location.pathname || SOURCE_PAGE,
    };
  });

  return attribution;
}

/**
 * Public instructor-application page. Posts to the SAME public lead endpoint the enterprise funnel
 * uses (POST /api/v1/public/leads via {@link submitEnterpriseLead}). The endpoint's schema has no
 * instructor-specific fields, so the extra answers (expertise, experience, course idea, portfolio)
 * are composed into the free-text `message` and the submission is tagged (company marker + UTM +
 * landing path) so the sales team can filter instructor applications out of the shared inbox.
 */
export function InstructorApplyPage() {
  const { t, locale } = useI18n();
  const attribution = useCapturedAttribution();
  const [pending, setPending] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const fired = useRef(false);
  useEffect(() => {
    if (fired.current) return;
    fired.current = true;
    track("page_view", { locale, path: SOURCE_PAGE });
  }, [locale]);

  const schema = z.object({
    full_name: z.string().min(1, t("teach.apply.required")),
    email: z.string().min(1, t("teach.apply.required")).email(t("teach.apply.invalidEmail")),
    phone: z.string().optional(),
    expertise: z.string().min(1, t("teach.apply.required")),
    experience: z.string().min(1, t("teach.apply.required")),
    course_idea: z.string().min(1, t("teach.apply.required")),
    portfolio: z
      .string()
      .optional()
      .refine((v) => !v || v.trim() === "" || /^https?:\/\/.+/i.test(v.trim()), t("teach.apply.invalidUrl")),
    consent: z.boolean().optional(),
    website: z.string().optional(),
  });

  type Values = z.infer<typeof schema>;

  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors },
  } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      full_name: "",
      email: "",
      phone: "",
      expertise: "",
      experience: "",
      course_idea: "",
      portfolio: "",
      consent: false,
      website: "",
    },
  });

  const onSubmit = handleSubmit(async (v) => {
    setFormError(null);

    // Honeypot: a filled `website` is a bot. Silently acknowledge and skip the network call.
    if (v.website && v.website.trim() !== "") {
      setSubmitted(true);
      return;
    }

    // Compose the instructor-specific answers into the lead's free-text message. Labels stay in
    // English so the sales inbox reads consistently regardless of the applicant's locale.
    const message = [
      "Instructor application",
      `Area of expertise: ${v.expertise.trim()}`,
      `Years of experience: ${EXPERIENCE_EN[v.experience as Experience] ?? v.experience}`,
      `What they want to teach: ${v.course_idea.trim()}`,
      v.portfolio?.trim() ? `Portfolio / LinkedIn: ${v.portfolio.trim()}` : null,
    ]
      .filter(Boolean)
      .join("\n");

    setPending(true);
    try {
      await submitEnterpriseLead({
        name: v.full_name.trim(),
        work_email: v.email.trim(),
        // No company for an individual instructor; use a stable marker so the CRM row is identifiable.
        company: "Instructor application",
        phone: v.phone?.trim() || undefined,
        request_type: "contact",
        message,
        source_page: attribution.sourcePage,
        // Preserve any real ad attribution, but default the tag so instructor leads are filterable.
        utm: {
          ...attribution.utm,
          source: attribution.utm.source ?? "instructor_application",
          campaign: attribution.utm.campaign ?? "become_an_instructor",
        },
        gclid: attribution.gclid,
        referrer: attribution.referrer,
        locale,
        marketing_consent: v.consent ?? false,
        website: "",
      });
      setSubmitted(true);
    } catch (err) {
      if (!applyApiFieldErrors(err, setError)) setFormError(errorMessage(err, t("teach.apply.errorGeneric")));
    } finally {
      setPending(false);
    }
  });

  return (
    <div className="space-y-14 py-2 sm:space-y-16">
      {/* Hero */}
      <section className="relative overflow-hidden rounded-3xl border border-border/70 bg-card p-8 sm:p-12">
        <div
          className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(90%_120%_at_100%_-10%,oklch(0.42_0.05_185/0.10)_0%,transparent_55%)]"
          aria-hidden
        />
        <div
          className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-copper/40 to-transparent"
          aria-hidden
        />
        <Reveal>
          <p className="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-copper">
            <GraduationCap className="size-4" aria-hidden />
            {t("teach.apply.eyebrow")}
          </p>
          <h1 className="mt-3 max-w-3xl font-serif text-3xl font-semibold tracking-tight sm:text-4xl">
            {t("teach.apply.title")}
          </h1>
          <p className="mt-4 max-w-2xl text-muted-foreground">{t("teach.apply.lead")}</p>
        </Reveal>
      </section>

      {/* Benefits */}
      <section aria-labelledby="apply-benefits">
        <Reveal>
          <h2 id="apply-benefits" className="font-serif text-2xl font-semibold">
            {t("teach.apply.benefitsTitle")}
          </h2>
        </Reveal>
        <div className="mt-6 grid gap-4 sm:grid-cols-3">
          {BENEFITS.map(({ icon: Icon, titleKey, bodyKey }) => (
            <Reveal key={titleKey}>
              <div className="h-full rounded-2xl border border-border/70 bg-card p-5">
                <span className="grid size-9 place-items-center rounded-lg bg-copper/10 text-copper">
                  <Icon className="size-5" aria-hidden />
                </span>
                <h3 className="mt-3 font-semibold">{t(titleKey)}</h3>
                <p className="mt-1 text-sm text-muted-foreground">{t(bodyKey)}</p>
              </div>
            </Reveal>
          ))}
        </div>
      </section>

      {/* Application form */}
      <section id="apply-form" className="scroll-mt-24 rounded-3xl border border-primary/20 bg-primary/[0.04] p-6 sm:p-10">
        <Reveal>
          <h2 className="font-serif text-2xl font-semibold sm:text-3xl">{t("teach.apply.formTitle")}</h2>
          <p className="mt-2 max-w-xl text-sm text-muted-foreground">{t("teach.apply.formSubtitle")}</p>
        </Reveal>

        <div className="mx-auto mt-8 max-w-2xl">
          {submitted ? (
            <div className="rounded-2xl border border-primary/25 bg-background p-8 text-center" role="status">
              <CheckCircle2 className="mx-auto size-8 text-primary" aria-hidden />
              <h3 className="mt-3 font-serif text-lg font-semibold">{t("teach.apply.successTitle")}</h3>
              <p className="mt-1 text-sm text-muted-foreground">{t("teach.apply.successBody")}</p>
              <Button
                type="button"
                variant="outline"
                className="mt-6"
                onClick={() => {
                  reset();
                  setSubmitted(false);
                }}
              >
                {t("teach.apply.submitAnother")}
              </Button>
            </div>
          ) : (
            <form onSubmit={onSubmit} className="grid gap-4 sm:grid-cols-2" noValidate>
              {formError ? (
                <div className="sm:col-span-2">
                  <FormAlert>{formError}</FormAlert>
                </div>
              ) : null}

              <Field id="full_name" label={t("teach.apply.fullName")} error={errors.full_name?.message} required>
                <Input
                  id="full_name"
                  autoComplete="name"
                  placeholder={t("teach.apply.fullNamePlaceholder")}
                  {...register("full_name")}
                />
              </Field>
              <Field id="email" label={t("teach.apply.email")} error={errors.email?.message} required>
                <Input
                  id="email"
                  type="email"
                  autoComplete="email"
                  placeholder={t("teach.apply.emailPlaceholder")}
                  {...register("email")}
                />
              </Field>
              <Field id="phone" label={t("teach.apply.phone")} error={errors.phone?.message}>
                <Input
                  id="phone"
                  type="tel"
                  autoComplete="tel"
                  placeholder={t("teach.apply.phonePlaceholder")}
                  {...register("phone")}
                />
              </Field>
              <Field id="expertise" label={t("teach.apply.expertise")} error={errors.expertise?.message} required>
                <Input
                  id="expertise"
                  placeholder={t("teach.apply.expertisePlaceholder")}
                  {...register("expertise")}
                />
              </Field>
              <div className="sm:col-span-2">
                <Field
                  id="experience"
                  label={t("teach.apply.experience")}
                  error={errors.experience?.message}
                  required
                >
                  <select id="experience" className={SELECT_CLASS} defaultValue="" {...register("experience")}>
                    <option value="" disabled>
                      {t("teach.apply.experiencePlaceholder")}
                    </option>
                    {EXPERIENCE_OPTIONS.map((exp) => (
                      <option key={exp} value={exp}>
                        {t(`teach.apply.exp.${exp}`)}
                      </option>
                    ))}
                  </select>
                </Field>
              </div>
              <div className="sm:col-span-2">
                <Field
                  id="course_idea"
                  label={t("teach.apply.courseIdea")}
                  error={errors.course_idea?.message}
                  required
                >
                  <Textarea
                    id="course_idea"
                    rows={5}
                    placeholder={t("teach.apply.courseIdeaPlaceholder")}
                    {...register("course_idea")}
                  />
                </Field>
              </div>
              <div className="sm:col-span-2">
                <Field id="portfolio" label={t("teach.apply.portfolio")} error={errors.portfolio?.message}>
                  <Input
                    id="portfolio"
                    type="url"
                    inputMode="url"
                    placeholder={t("teach.apply.portfolioPlaceholder")}
                    {...register("portfolio")}
                  />
                </Field>
              </div>

              <div className="sm:col-span-2">
                <label className="flex items-start gap-2 text-sm text-muted-foreground">
                  <input type="checkbox" className="mt-1 size-4" {...register("consent")} />
                  <span>{t("teach.apply.consent")}</span>
                </label>
              </div>

              {/* Honeypot — off-screen (not display:none, so it stays fillable by bots). */}
              <div aria-hidden className="absolute left-[-9999px] top-auto h-px w-px overflow-hidden">
                <label htmlFor="website">Website</label>
                <input id="website" type="text" tabIndex={-1} autoComplete="off" {...register("website")} />
              </div>

              <div className="sm:col-span-2">
                <Button type="submit" size="lg" className="w-full sm:w-auto" loading={pending}>
                  {pending ? t("teach.apply.submitting") : t("teach.apply.submit")}
                </Button>
              </div>
            </form>
          )}
        </div>
      </section>
    </div>
  );
}
