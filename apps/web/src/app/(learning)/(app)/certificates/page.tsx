"use client";

import { Award, Download, Share2 } from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useCertificateDownload, useCertificateShare, useMyCertificates } from "@/lib/student/hooks";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { EmptyState } from "@/components/states/empty-state";
import { toast } from "@/components/ui/toast";

export default function CertificatesPage() {
  const { t } = useI18n();
  const query = useMyCertificates();
  const download = useCertificateDownload();
  const share = useCertificateShare();

  const onDownload = (id: string) =>
    download.mutate(id, {
      onSuccess: (res) => window.open(res.data.download_url, "_blank", "noopener,noreferrer"),
      onError: (e) => toast.error(errorMessage(e, t("common.error"))),
    });

  const onShare = (id: string) =>
    share.mutate(id, {
      onSuccess: async (res) => {
        const url = String(res.data.verification_url ?? "");
        try {
          await navigator.clipboard.writeText(url);
        } catch {
          /* clipboard may be blocked; toast still shows the link is ready */
        }
        toast.success(t("student.certificates.shareReady"));
      },
      onError: (e) => toast.error(errorMessage(e, t("common.error"))),
    });

  return (
    <div>
      <PageHeader eyebrow="ACHIEVEMENTS" icon="Award" title={t("student.certificates.title")} subtitle={t("student.certificates.subtitle")} />
      <QueryState
        query={query}
        isEmpty={(d) => d.length === 0}
        empty={<EmptyState icon={<Award className="size-8" />} title={t("student.certificates.empty")} />}
      >
        {(items) => (
          <div className="stagger-in grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {items.map((c) => (
              <Card key={c.id} className="group relative overflow-hidden border-border/70 transition-all duration-300 hover:-translate-y-0.5 hover:border-copper/30 hover:shadow-lg">
                <div className="pointer-events-none absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-copper via-gold to-copper" aria-hidden />
                <CardContent className="space-y-4 p-5 pt-6">
                  <div className="flex items-start justify-between gap-2">
                    <div className="flex size-12 items-center justify-center rounded-2xl bg-copper/10 text-copper ring-1 ring-copper/20 transition-transform duration-300 group-hover:scale-105">
                      <Award className="size-6" aria-hidden />
                    </div>
                    <Badge variant={c.status === "issued" ? "success" : "secondary"}>{c.status}</Badge>
                  </div>
                  <div className="space-y-1">
                    <h3 className="line-clamp-2 font-serif text-lg font-semibold leading-tight">{c.course_title ?? "—"}</h3>
                    <p className="text-xs text-muted-foreground">
                      {t("student.certificates.number")} <span className="font-medium text-foreground">{c.number}</span>
                    </p>
                    {c.issued_at ? (
                      <p className="text-xs text-muted-foreground">
                        {t("student.certificates.issued")}: {new Date(c.issued_at).toLocaleDateString()}
                      </p>
                    ) : null}
                  </div>
                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      className="flex-1"
                      loading={download.isPending && download.variables === c.id}
                      onClick={() => onDownload(c.id)}
                    >
                      <Download className="size-4" aria-hidden /> {t("student.certificates.download")}
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      className="flex-1"
                      loading={share.isPending && share.variables === c.id}
                      onClick={() => onShare(c.id)}
                    >
                      <Share2 className="size-4" aria-hidden /> {t("student.certificates.share")}
                    </Button>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
      </QueryState>
    </div>
  );
}
