"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation } from "@tanstack/react-query";
import Link from "next/link";
import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { z } from "zod";
import { applyApiFieldErrors, errorMessage } from "@/lib/api/errors";
import { registerUser } from "@/lib/auth/api";
import { useAuth } from "@/lib/auth/auth-context";
import { useI18n } from "@/lib/i18n/i18n-context";
import { AuthCard } from "@/components/auth/auth-card";
import { Field } from "@/components/auth/field";
import { FormAlert } from "@/components/auth/form-alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";

type Values = {
  account_type: "personal" | "company";
  name: string;
  email: string;
  phone?: string;
  password: string;
  password_confirmation: string;
  terms: boolean;
  company_name?: string;
  company_size?: string;
  company_country?: string;
  company_tax_id?: string;
};

export default function RegisterPage() {
  const { t, locale } = useI18n();
  const auth = useAuth();
  const [formError, setFormError] = useState<string | null>(null);

  const schema = z
    .object({
      name: z.string().min(1, t("auth.validation.required")),
      email: z.string().min(1, t("auth.validation.required")).email(t("auth.validation.email")),
      phone: z.string().optional(),
      password: z.string().min(8, t("auth.validation.min8")),
      password_confirmation: z.string().min(1, t("auth.validation.required")),
      terms: z.boolean(),
      account_type: z.enum(["personal", "company"]),
      company_name: z.string().optional(),
      company_size: z.string().optional(),
      company_country: z.string().optional(),
      company_tax_id: z.string().optional(),
    })
    // A company account is the organization's registration too, so it must at least be named.
    .refine((d) => d.account_type !== "company" || (d.company_name ?? "").trim().length > 0, {
      path: ["company_name"],
      message: t("auth.validation.required"),
    })
    .refine((d) => d.password === d.password_confirmation, {
      path: ["password_confirmation"],
      message: t("auth.validation.passwordsMatch"),
    })
    .refine((d) => d.terms === true, { path: ["terms"], message: t("auth.validation.terms") });

  const {
    register,
    handleSubmit,
    setError,
    control,
    formState: { errors },
  } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      account_type: "personal",
      name: "", email: "", phone: "", password: "", password_confirmation: "", terms: false,
      company_name: "", company_size: "", company_country: "", company_tax_id: "",
    },
  });

  // useWatch (not watch()) keeps this compatible with the React Compiler.
  const accountType = useWatch({ control, name: "account_type" });

  const mutation = useMutation({
    mutationFn: async (v: Values) => {
      await registerUser({
        name: v.name,
        email: v.email,
        phone: v.phone?.trim() ? v.phone.trim() : undefined,
        password: v.password,
        password_confirmation: v.password_confirmation,
        locale,
        account_type: v.account_type,
        company:
          v.account_type === "company"
            ? {
                name: (v.company_name ?? "").trim(),
                size: v.company_size?.trim() || undefined,
                country: v.company_country?.trim() || undefined,
                tax_id: v.company_tax_id?.trim() || undefined,
              }
            : undefined,
      });
      // Best-effort sign-in so the (authenticated) email-verification step works immediately.
      try {
        await auth.login(v.email, v.password);
      } catch {
        /* verification page prompts sign-in if this fails */
      }
    },
    onSuccess: () => window.location.assign("/verify-email"),
    onError: (err) => {
      if (!applyApiFieldErrors(err, setError)) setFormError(errorMessage(err, t("auth.genericError")));
    },
  });

  const onSubmit = handleSubmit((v) => {
    setFormError(null);
    mutation.mutate(v);
  });

  return (
    <AuthCard
      title={t("auth.register.title")}
      subtitle={t("auth.register.subtitle")}
      footer={
        <span>
          {t("auth.register.haveAccount")}{" "}
          <Link className="font-medium text-primary hover:underline" href="/login">
            {t("auth.register.login")}
          </Link>
        </span>
      }
    >
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        {formError ? <FormAlert>{formError}</FormAlert> : null}

        {/* Account type decides what is created: a person, or a person plus the organization they
            buy for. Company-only products can only be bought by the latter. */}
        <fieldset className="space-y-2">
          <legend className="text-sm font-medium">{t("auth.register.accountType")}</legend>
          <div className="grid grid-cols-2 gap-2">
            {(["personal", "company"] as const).map((type) => (
              <label
                key={type}
                className={`cursor-pointer rounded-lg border p-3 text-sm transition-colors ${
                  accountType === type ? "border-primary bg-primary/5 font-medium" : "border-border hover:border-primary/40"
                }`}
              >
                <input type="radio" value={type} className="sr-only" {...register("account_type")} />
                {type === "personal" ? t("auth.register.personal") : t("auth.register.company")}
              </label>
            ))}
          </div>
        </fieldset>

        <Field id="name" label={t("auth.name")} error={errors.name?.message}>
          <Input id="name" autoComplete="name" {...register("name")} />
        </Field>
        <Field id="email" label={t("auth.email")} error={errors.email?.message}>
          <Input id="email" type="email" autoComplete="email" placeholder={t("auth.emailPlaceholder")} {...register("email")} />
        </Field>
        <Field id="phone" label={t("auth.phone")} error={errors.phone?.message}>
          <Input id="phone" type="tel" autoComplete="tel" {...register("phone")} />
        </Field>
        <Field id="password" label={t("auth.password")} error={errors.password?.message}>
          <Input id="password" type="password" autoComplete="new-password" {...register("password")} />
        </Field>
        <Field id="password_confirmation" label={t("auth.confirmPassword")} error={errors.password_confirmation?.message}>
          <Input id="password_confirmation" type="password" autoComplete="new-password" {...register("password_confirmation")} />
        </Field>
        {accountType === "company" ? (
          <div className="space-y-4 rounded-lg border border-border bg-surface/40 p-4">
            <p className="text-sm font-medium">{t("auth.register.companyDetails")}</p>
            <Field id="company_name" label={t("auth.register.companyName")} error={errors.company_name?.message}>
              <Input id="company_name" autoComplete="organization" {...register("company_name")} />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field id="company_size" label={t("auth.register.companySize")} error={errors.company_size?.message}>
                <Input id="company_size" placeholder="51-200" {...register("company_size")} />
              </Field>
              <Field id="company_country" label={t("auth.register.companyCountry")} error={errors.company_country?.message}>
                <Input id="company_country" autoComplete="country-name" {...register("company_country")} />
              </Field>
            </div>
            <Field id="company_tax_id" label={t("auth.register.companyTaxId")} error={errors.company_tax_id?.message}>
              <Input id="company_tax_id" {...register("company_tax_id")} />
            </Field>
            <p className="text-xs text-muted-foreground">{t("auth.register.companyHint")}</p>
          </div>
        ) : null}

        <div className="space-y-1.5">
          <label className="flex items-start gap-2 text-sm text-muted-foreground">
            <Checkbox className="mt-0.5" {...register("terms")} />
            <span>{t("auth.register.terms")}</span>
          </label>
          {errors.terms?.message ? (
            <p role="alert" className="text-xs font-medium text-destructive">
              {errors.terms.message}
            </p>
          ) : null}
        </div>
        <Button type="submit" className="w-full" loading={mutation.isPending}>
          {t("auth.register.submit")}
        </Button>
      </form>
    </AuthCard>
  );
}
