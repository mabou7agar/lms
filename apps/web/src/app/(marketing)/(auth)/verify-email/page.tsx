"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation } from "@tanstack/react-query";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { hasSession } from "@/lib/api/client";
import { useHydrated } from "@/hooks/use-hydrated";
import { applyApiFieldErrors, errorMessage } from "@/lib/api/errors";
import { verifyEmail } from "@/lib/auth/api";
import { useAuth } from "@/lib/auth/auth-context";
import { useI18n } from "@/lib/i18n/i18n-context";
import { AuthCard } from "@/components/auth/auth-card";
import { Field } from "@/components/auth/field";
import { FormAlert } from "@/components/auth/form-alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { PageLoading } from "@/components/states/loading-state";

type Values = { code: string };

export default function VerifyEmailPage() {
  const { t } = useI18n();
  const router = useRouter();
  const auth = useAuth();
  // The session marker is a client-only cookie, so resolve it after hydration (never during SSR)
  // to avoid a hydration mismatch. `ready` gates the loader exactly as the previous mount effect did.
  const ready = useHydrated();
  const authed = ready && hasSession();
  const [done, setDone] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const schema = z.object({ code: z.string().min(1, t("auth.validation.code")) });
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { code: "" } });

  const mutation = useMutation({
    mutationFn: async (v: Values) => {
      await verifyEmail(v.code);
      await auth.refresh();
    },
    onSuccess: () => {
      setDone(true);
      setTimeout(() => router.replace("/"), 1200);
    },
    onError: (err) => {
      if (!applyApiFieldErrors(err, setError)) setFormError(errorMessage(err, t("auth.genericError")));
    },
  });

  const onSubmit = handleSubmit((v) => {
    setFormError(null);
    mutation.mutate(v);
  });

  // Nothing left to verify. The page is no longer guest-guarded, so it has to send an already
  // verified account on itself rather than showing it a code form it cannot use. `done` is excluded
  // so the success message still gets its moment before the page's own redirect fires.
  const alreadyVerified = auth.status === "authenticated" && auth.user?.email_verified === true;
  useEffect(() => {
    if (alreadyVerified && !done) router.replace("/dashboard");
  }, [alreadyVerified, done, router]);

  if (!ready) return <PageLoading />;

  return (
    <AuthCard
      title={t("auth.verify.title")}
      subtitle={t("auth.verify.subtitle")}
      footer={
        <Link className="font-medium text-primary hover:underline" href="/login">
          {t("auth.forgot.back")}
        </Link>
      }
    >
      {!authed ? (
        <FormAlert>{t("auth.verify.needLogin")}</FormAlert>
      ) : done ? (
        <FormAlert variant="success">{t("auth.verify.success")}</FormAlert>
      ) : (
        <form onSubmit={onSubmit} className="space-y-4" noValidate>
          {formError ? <FormAlert>{formError}</FormAlert> : null}
          <Field id="code" label={t("auth.code")} error={errors.code?.message}>
            <Input id="code" inputMode="numeric" autoComplete="one-time-code" {...register("code")} />
          </Field>
          <Button type="submit" className="w-full" loading={mutation.isPending}>
            {t("auth.verify.submit")}
          </Button>
        </form>
      )}
    </AuthCard>
  );
}
