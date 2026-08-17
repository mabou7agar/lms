import Link from "next/link";
import { GraduationCap, ArrowRight } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ProgressBar } from "./progress-bar";

export interface CourseProgressCardProps {
  title: string;
  progress: number;
  status?: string;
  continueHref?: string;
  continueLabel?: string;
  subtitle?: string;
  /**
   * The access window on this enrollment has closed — either an employer's seat or a purchase of
   * time-limited access. The card stays visible on purpose: a course vanishing without explanation
   * is worse than one that says why it can no longer be opened.
   */
  expired?: boolean;
  /** Whose access ran out decides who the learner has to talk to to get it back. */
  companyGranted?: boolean;
}

export function CourseProgressCard({ title, progress, status, continueHref = "/continue-learning", continueLabel, subtitle, expired = false, companyGranted = false }: CourseProgressCardProps) {
  const { t } = useI18n();
  const pct = Math.round(progress);
  const done = pct >= 100;
  return (
    <Card className="group flex flex-col overflow-hidden border-border/70 transition-all duration-300 hover:-translate-y-0.5 hover:border-copper/30 hover:shadow-lg">
      <CardContent className="flex flex-1 flex-col gap-4 p-5">
        <div className="flex items-start gap-3">
          <div className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-copper/10 text-copper transition-transform duration-300 group-hover:scale-105">
            <GraduationCap className="size-5" aria-hidden />
          </div>
          <div className="min-w-0 flex-1">
            <h3 className="line-clamp-2 font-serif text-base font-semibold leading-tight">{title}</h3>
            {subtitle ? <p className="mt-0.5 truncate text-xs text-muted-foreground">{subtitle}</p> : null}
          </div>
          {expired ? (
            <Badge variant="secondary">{t("student.accessEnded")}</Badge>
          ) : status ? (
            <Badge variant={done ? "success" : "secondary"}>{status}</Badge>
          ) : null}
        </div>
        <div className="mt-auto space-y-2">
          <div className="flex items-center justify-between text-xs">
            <span className="text-muted-foreground">{t("student.progress")}</span>
            <span className="font-serif text-sm font-semibold tabular-nums text-copper">{pct}%</span>
          </div>
          <ProgressBar value={pct} />
        </div>
        {expired ? (
          <p className="rounded-md border bg-muted/40 p-2 text-center text-xs text-muted-foreground">
            {t(companyGranted ? "student.accessEndedHint" : "student.accessEndedHintPurchase")}
          </p>
        ) : (
          <Button asChild className="w-full" variant={done ? "outline" : "default"}>
            <Link href={continueHref}>
              {continueLabel ?? t("student.continue")}
              <ArrowRight className="size-4 rtl:rotate-180" aria-hidden />
            </Link>
          </Button>
        )}
      </CardContent>
    </Card>
  );
}
