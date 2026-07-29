"use client";

import { use, useState } from "react";
import Link from "next/link";
import { zodResolver } from "@hookform/resolvers/zod";
import { Controller, useForm } from "react-hook-form";
import { z } from "zod";
import { ArrowLeft, UserPlus } from "lucide-react";
import { applyApiFieldErrors, errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useInviteMember, useOrganization } from "@/lib/org/hooks";
import type { MemberRole } from "@/lib/org/api";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { SectionCard } from "@/components/org/section-card";
import { MemberRow } from "@/components/org/member-row";
import { Field } from "@/components/auth/field";
import { FormAlert } from "@/components/auth/form-alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

const ROLES: MemberRole[] = ["owner", "admin", "manager", "member"];

type InviteValues = { email: string; role: MemberRole };

function InviteMemberForm({ orgId }: { orgId: string }) {
  const { t } = useI18n();
  const invite = useInviteMember(orgId);
  const [formError, setFormError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  const schema = z.object({
    email: z.string().min(1, t("common.error")).email(t("common.error")),
    role: z.enum(["owner", "admin", "manager", "member"]),
  });

  const {
    control,
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors },
  } = useForm<InviteValues>({ resolver: zodResolver(schema), defaultValues: { email: "", role: "member" } });

  const onSubmit = handleSubmit((v) => {
    setFormError(null);
    setDone(false);
    invite.mutate(v, {
      onSuccess: () => {
        setDone(true);
        reset({ email: "", role: "member" });
      },
      onError: (err) => {
        if (!applyApiFieldErrors(err, setError)) setFormError(errorMessage(err, t("org.error")));
      },
    });
  });

  return (
    <form onSubmit={onSubmit} className="space-y-4" noValidate>
      {done ? <FormAlert variant="success">{t("org.invite.success")}</FormAlert> : null}
      {formError ? <FormAlert>{formError}</FormAlert> : null}
      <Field id="invite-email" label={t("org.invite.email")} error={errors.email?.message}>
        <Input id="invite-email" type="email" placeholder={t("org.invite.emailPlaceholder")} {...register("email")} />
      </Field>
      <Field id="invite-role" label={t("org.invite.role")} error={errors.role?.message}>
        <Controller
          control={control}
          name="role"
          render={({ field }) => (
            <Select value={field.value} onValueChange={(val) => field.onChange(val as MemberRole)}>
              <SelectTrigger id="invite-role">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {ROLES.map((r) => (
                  <SelectItem key={r} value={r}>
                    {t(`org.roles.${r}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        />
      </Field>
      <Button type="submit" disabled={invite.isPending} className="w-full">
        <UserPlus className="size-4" aria-hidden />
        {invite.isPending ? t("org.invite.sending") : t("org.invite.submit")}
      </Button>
    </form>
  );
}

export default function OrganizationDetailsPage({ params }: { params: Promise<{ public_id: string }> }) {
  const { public_id } = use(params);
  const { t } = useI18n();
  const query = useOrganization(public_id);

  return (
    <div className="space-y-6">
      <Button asChild variant="ghost" size="sm" className="w-fit">
        <Link href="/org/organizations">
          <ArrowLeft className="size-4" aria-hidden /> {t("org.details.back")}
        </Link>
      </Button>

      <QueryState query={query}>
        {(org) => (
          <div className="space-y-6">
            <PageHeader
              title={org.name}
              subtitle={org.slug}
              action={<Badge variant="outline">{org.status}</Badge>}
            />

            <div className="grid gap-6 lg:grid-cols-3">
              <div className="space-y-6 lg:col-span-2">
                <SectionCard title={t("org.details.profile")}>
                  <dl className="grid gap-3 sm:grid-cols-2">
                    <div>
                      <dt className="text-xs text-muted-foreground">{t("org.details.status")}</dt>
                      <dd className="text-sm font-medium">{org.status}</dd>
                    </div>
                    <div>
                      <dt className="text-xs text-muted-foreground">{t("org.details.size")}</dt>
                      <dd className="text-sm font-medium">{org.size ?? "—"}</dd>
                    </div>
                    <div className="sm:col-span-2">
                      <dt className="text-xs text-muted-foreground">{t("org.details.website")}</dt>
                      <dd className="text-sm font-medium">
                        {org.website ? (
                          <a className="text-primary hover:underline" href={org.website} target="_blank" rel="noreferrer noopener">
                            {org.website}
                          </a>
                        ) : (
                          "—"
                        )}
                      </dd>
                    </div>
                  </dl>
                </SectionCard>

                <SectionCard
                  title={`${t("org.details.members")} (${org.members_count ?? org.members?.length ?? 0})`}
                >
                  {org.members && org.members.length > 0 ? (
                    <div className="space-y-2">
                      {org.members.map((m) => (
                        <MemberRow key={m.id} member={m} />
                      ))}
                    </div>
                  ) : (
                    <p className="text-sm text-muted-foreground">{t("org.details.membersEmpty")}</p>
                  )}
                </SectionCard>

                <div className="grid gap-6 sm:grid-cols-3">
                  <SectionCard title={t("org.details.teams")}>
                    <p className="text-sm text-muted-foreground">{t("org.details.notAvailable")}</p>
                  </SectionCard>
                  <SectionCard title={t("org.details.departments")}>
                    <p className="text-sm text-muted-foreground">{t("org.details.notAvailable")}</p>
                  </SectionCard>
                  <SectionCard title={t("org.details.seatUsage")}>
                    <div className="text-2xl font-bold tabular-nums">{org.members_count ?? org.members?.length ?? 0}</div>
                    <p className="text-xs text-muted-foreground">{t("org.details.members")}</p>
                  </SectionCard>
                </div>
              </div>

              <div className="lg:col-span-1">
                <SectionCard title={t("org.invite.title")}>
                  <InviteMemberForm orgId={public_id} />
                </SectionCard>
              </div>
            </div>
          </div>
        )}
      </QueryState>
    </div>
  );
}
