"use client";

import { use, useState } from "react";
import Link from "next/link";
import { MailCheck, Check, X } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useAcceptInvitation, useDeclineInvitation } from "@/lib/enterprise/manager-hooks";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { FormAlert } from "@/components/auth/form-alert";

type Outcome = "accepted" | "declined" | null;

export default function InvitationPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = use(params);
  const { t } = useI18n();

  const accept = useAcceptInvitation();
  const decline = useDeclineInvitation();

  const [outcome, setOutcome] = useState<Outcome>(null);
  const [error, setError] = useState<string | null>(null);

  const onAccept = () => {
    setError(null);
    accept.mutate(token, {
      onSuccess: () => setOutcome("accepted"),
      onError: (err) => setError(errorMessage(err, t("manager.error"))),
    });
  };

  const onDecline = () => {
    setError(null);
    decline.mutate(token, {
      onSuccess: () => setOutcome("declined"),
      onError: (err) => setError(errorMessage(err, t("manager.error"))),
    });
  };

  const busy = accept.isPending || decline.isPending;

  return (
    <Card className="w-full">
      <CardHeader>
        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-copper">{t("manager.invitation.eyebrow")}</p>
        <CardTitle className="flex items-center gap-2">
          <MailCheck className="size-5" aria-hidden /> {t("manager.invitation.title")}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        {error ? <FormAlert>{`${t("manager.invitation.errorTitle")} ${error}`}</FormAlert> : null}

        {outcome === "accepted" ? (
          <div className="space-y-4">
            <FormAlert variant="success">{t("manager.invitation.accepted")}</FormAlert>
            <Button asChild className="w-full">
              <Link href="/manager">{t("nav.managerPortal")}</Link>
            </Button>
          </div>
        ) : outcome === "declined" ? (
          <FormAlert variant="success">{t("manager.invitation.declined")}</FormAlert>
        ) : (
          <>
            <p className="text-sm text-muted-foreground">{t("manager.invitation.subtitle")}</p>
            <div className="flex gap-2">
              <Button onClick={onAccept} disabled={busy} className="flex-1">
                <Check className="size-4" aria-hidden />
                {accept.isPending ? t("manager.invitation.accepting") : t("manager.invitation.accept")}
              </Button>
              <Button variant="outline" onClick={onDecline} disabled={busy} className="flex-1">
                <X className="size-4" aria-hidden />
                {decline.isPending ? t("manager.invitation.declining") : t("manager.invitation.decline")}
              </Button>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
}
