"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation } from "@tanstack/react-query";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { applyApiFieldErrors, errorMessage, isMfaRequired } from "@/lib/api/errors";
import { useAuth } from "@/lib/auth/auth-context";
import { useI18n } from "@/lib/i18n/i18n-context";
import { safeRedirect } from "@/lib/utils";
import { AuthCard } from "@/components/auth/auth-card";
import { Field } from "@/components/auth/field";
import { FormAlert } from "@/components/auth/form-alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";

type Values = { email: string; password: string; remember?: boolean; mfa_code?: string };

function LoginForm() {
  const { t } = useI18n();
  const auth = useAuth();
  const router = useRouter();
  const params = useSearchParams();
  const [formError, setFormError] = useState<string | null>(null);
  const [mfa, setMfa] = useState(false);

  const schema = z.object({
    email: z.string().min(1, t("auth.validation.required")).email(t("auth.validation.email")),
    password: z.string().min(1, t("auth.validation.required")),
    remember: z.boolean().optional(),
    mfa_code: z.string().optional(),
  });

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { email: "", password: "", remember: false, mfa_code: "" } });

  const mutation = useMutation({
    mutationFn: (v: Values) => auth.login(v.email, v.password, mfa ? v.mfa_code : undefined),
    // With no explicit redirect target, land on the authenticated dashboard rather than the public
    // marketing home (safeRedirect's default "/"), which reads to users as "login did nothing".
    onSuccess: () => router.replace(safeRedirect(params.get("redirect") ?? "/dashboard")),
    onError: (err) => {
      if (isMfaRequired(err)) {
        setMfa(true);
        setFormError(t("auth.mfa.subtitle"));
        return;
      }
      if (!applyApiFieldErrors(err, setError)) setFormError(errorMessage(err, t("auth.genericError")));
    },
  });

  const onSubmit = handleSubmit((v) => {
    setFormError(null);
    mutation.mutate(v);
  });

  return (
    <AuthCard
      title={t("auth.login.title")}
      subtitle={t("auth.login.subtitle")}
      footer={
        <span>
          {t("auth.login.noAccount")}{" "}
          <Link className="font-medium text-primary hover:underline" href="/register">
            {t("auth.login.register")}
          </Link>
        </span>
      }
    >
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        {formError ? <FormAlert>{formError}</FormAlert> : null}
        <Field id="email" label={t("auth.email")} error={errors.email?.message}>
          <Input id="email" type="email" autoComplete="email" placeholder={t("auth.emailPlaceholder")} {...register("email")} />
        </Field>
        <Field id="password" label={t("auth.password")} error={errors.password?.message}>
          <Input id="password" type="password" autoComplete="current-password" {...register("password")} />
        </Field>
        {mfa ? (
          <Field id="mfa_code" label={t("auth.code")} error={errors.mfa_code?.message}>
            <Input id="mfa_code" inputMode="numeric" autoComplete="one-time-code" {...register("mfa_code")} />
          </Field>
        ) : null}
        <div className="flex items-center justify-between gap-3">
          <label className="flex items-center gap-2 text-sm text-muted-foreground">
            <Checkbox {...register("remember")} /> {t("auth.login.remember")}
          </label>
          <Link className="text-sm font-medium text-primary hover:underline" href="/forgot-password">
            {t("auth.login.forgot")}
          </Link>
        </div>
        <Button type="submit" className="w-full" loading={mutation.isPending}>
          {t("auth.login.submit")}
        </Button>
      </form>
    </AuthCard>
  );
}

export default function LoginPage() {
  return (
    <Suspense>
      <LoginForm />
    </Suspense>
  );
}
