"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation } from "@tanstack/react-query";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { hasSession } from "@/lib/api/client";
import { useHydrated } from "@/hooks/use-hydrated";
import { applyApiFieldErrors, errorMessage } from "@/lib/api/errors";
import { verifyMfa } from "@/lib/auth/api";
import { useI18n } from "@/lib/i18n/i18n-context";
import { AuthCard } from "@/components/auth/auth-card";
import { Field } from "@/components/auth/field";
import { FormAlert } from "@/components/auth/form-alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { PageLoading } from "@/components/states/loading-state";

type Values = { code: string };

export default function MfaPage() {
  const { t } = useI18n();
  const router = useRouter();
  // The session marker is a client-only cookie, so resolve it after hydration (never during SSR)
  // to avoid a hydration mismatch. `ready` gates the loader exactly as the previous mount effect did.
  const ready = useHydrated();
  const authed = ready && hasSession();
  const [formError, setFormError] = useState<string | null>(null);

  const schema = z.object({ code: z.string().min(1, t("auth.validation.code")) });
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { code: "" } });

  const mutation = useMutation({
    mutationFn: (v: Values) => verifyMfa(v.code),
    onSuccess: () => router.replace("/"),
    onError: (err) => {
      if (!applyApiFieldErrors(err, setError)) setFormError(errorMessage(err, t("auth.genericError")));
    },
  });

  const onSubmit = handleSubmit((v) => {
    setFormError(null);
    mutation.mutate(v);
  });

  if (!ready) return <PageLoading />;

  return (
    <AuthCard
      title={t("auth.mfa.title")}
      subtitle={t("auth.mfa.subtitle")}
      footer={
        <Link className="font-medium text-primary hover:underline" href="/login">
          {t("auth.forgot.back")}
        </Link>
      }
    >
      {!authed ? (
        <FormAlert>{t("auth.mfa.needLogin")}</FormAlert>
      ) : (
        <form onSubmit={onSubmit} className="space-y-4" noValidate>
          {formError ? <FormAlert>{formError}</FormAlert> : null}
          <Field id="code" label={t("auth.code")} error={errors.code?.message} hint={t("auth.mfa.recoveryHint")}>
            <Input id="code" inputMode="text" autoComplete="one-time-code" {...register("code")} />
          </Field>
          <Button type="submit" className="w-full" loading={mutation.isPending}>
            {t("auth.mfa.submit")}
          </Button>
        </form>
      )}
    </AuthCard>
  );
}
