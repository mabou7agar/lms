"use client";

import Link from "next/link";
import { Award, BookOpen, Gauge, PlayCircle, ArrowRight, Bell } from "lucide-react";
import { useAuth } from "@/lib/auth/auth-context";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useContinueLearning, useMyCertificates, useMyLearning, useNotifications } from "@/lib/student/hooks";
import { PageHeader } from "@/components/student/page-header";
import { StatCard } from "@/components/student/stat-card";
import { ProgressBar } from "@/components/student/progress-bar";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { Reveal } from "@/components/landing/reveal";
import { UpcomingSessions } from "@/components/learning/upcoming-sessions";

function avg(nums: number[]): number {
  return nums.length === 0 ? 0 : Math.round(nums.reduce((a, b) => a + b, 0) / nums.length);
}

export default function DashboardPage() {
  const { t } = useI18n();
  const { user } = useAuth();
  const learning = useMyLearning();
  const resume = useContinueLearning();
  const certs = useMyCertificates();
  const notifications = useNotifications(1);

  const courses = learning.data ?? [];
  const resumeItems = resume.data ?? [];
  const recent = (notifications.data?.data ?? []).slice(0, 4);
  const primary = resumeItems[0];
  const rest = resumeItems.slice(1, 3);

  return (
    <div className="space-y-6">
      <PageHeader eyebrow="OVERVIEW" icon="LayoutDashboard"
        title={`${t("student.dashboard.welcome")}${user?.name ? `, ${user.name}` : ""}`}
        subtitle={t("student.dashboard.welcomeSub")}
      />

      <div className="stagger-in grid gap-4 sm:grid-cols-3">
        {learning.isPending ? (
          <>
            <Skeleton className="h-24" />
            <Skeleton className="h-24" />
            <Skeleton className="h-24" />
          </>
        ) : (
          <>
            <StatCard label={t("student.dashboard.myCourses")} value={courses.length} icon={BookOpen} />
            <StatCard label={t("student.dashboard.avgProgress")} value={`${avg(courses.map((c) => c.progress_percentage))}%`} icon={Gauge} />
            <StatCard label={t("student.dashboard.certificates")} value={certs.data?.length ?? 0} icon={Award} />
          </>
        )}
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Reveal as="section" className="lg:col-span-2">
          <Card className="h-full overflow-hidden border-border/70">
            <CardHeader className="flex-row items-center justify-between">
              <CardTitle className="font-serif text-xl">{t("student.dashboard.continueLearning")}</CardTitle>
              <Button asChild variant="ghost" size="sm" className="text-copper">
                <Link href="/my-learning">
                  {t("student.viewAll")} <ArrowRight className="size-4 rtl:rotate-180" aria-hidden />
                </Link>
              </Button>
            </CardHeader>
            <CardContent className="space-y-5">
              {resume.isPending ? (
                <Skeleton className="h-28" />
              ) : resumeItems.length === 0 ? (
                <div className="rounded-2xl border border-dashed border-border/70 p-8 text-center">
                  <p className="text-sm text-muted-foreground">{t("student.dashboard.nothingToResume")}</p>
                  <Button asChild size="sm" className="mt-4">
                    <Link href="/courses">{t("student.dashboard.myCourses")} <ArrowRight className="size-4 rtl:rotate-180" aria-hidden /></Link>
                  </Button>
                </div>
              ) : (
                <>
                  {/* Primary resume card */}
                  <div className="relative overflow-hidden rounded-2xl border border-border/70 bg-surface/40 p-5">
                    <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-copper/40 to-transparent" aria-hidden />
                    <div className="flex items-start gap-4">
                      <span className="grid size-12 shrink-0 place-items-center rounded-xl bg-copper/10 text-copper">
                        <PlayCircle className="size-6" aria-hidden />
                      </span>
                      <div className="min-w-0 flex-1">
                        <p className="text-xs font-semibold uppercase tracking-wider text-copper">{t("student.resume")}</p>
                        <h3 className="mt-0.5 line-clamp-2 font-serif text-lg font-semibold leading-tight">{primary.course.title}</h3>
                        <div className="mt-3 flex items-center gap-3">
                          <ProgressBar value={primary.progress_percentage} className="flex-1" />
                          <span className="font-serif text-sm font-semibold tabular-nums text-copper">{Math.round(primary.progress_percentage)}%</span>
                        </div>
                      </div>
                    </div>
                    <Button asChild className="mt-4 w-full shine relative overflow-hidden sm:w-auto">
                      <Link href={`/learn/${primary.course.id}`}>
                        {t("student.resume")} <ArrowRight className="size-4 rtl:rotate-180" aria-hidden />
                      </Link>
                    </Button>
                  </div>

                  {rest.map((it) => (
                    <div key={it.course.id} className="flex items-center gap-3 rounded-xl border border-border/60 p-3">
                      <div className="min-w-0 flex-1 space-y-1.5">
                        <p className="truncate text-sm font-medium">{it.course.title}</p>
                        <div className="flex items-center gap-2">
                          <ProgressBar value={it.progress_percentage} className="flex-1" />
                          <span className="text-xs tabular-nums text-muted-foreground">{Math.round(it.progress_percentage)}%</span>
                        </div>
                      </div>
                      <Button asChild size="sm" variant="outline">
                        <Link href={`/learn/${it.course.id}`}>
                          <PlayCircle className="size-4" aria-hidden /> {t("student.resume")}
                        </Link>
                      </Button>
                    </div>
                  ))}
                </>
              )}
            </CardContent>
          </Card>
        </Reveal>

        <Reveal as="section" delay={120}>
          <Card className="h-full border-border/70">
            <CardHeader className="flex-row items-center justify-between">
              <CardTitle className="flex items-center gap-2 font-serif text-xl">
                <Bell className="size-4 text-copper" aria-hidden /> {t("student.dashboard.recentNotifications")}
              </CardTitle>
              <Button asChild variant="ghost" size="sm" className="text-copper">
                <Link href="/notifications">{t("student.viewAll")}</Link>
              </Button>
            </CardHeader>
            <CardContent className="space-y-1">
              {notifications.isPending ? (
                <Skeleton className="h-16" />
              ) : recent.length === 0 ? (
                <p className="py-6 text-center text-sm text-muted-foreground">{t("student.notifications.empty")}</p>
              ) : (
                recent.map((n) => (
                  <div key={n.id} className="flex items-start gap-3 rounded-xl p-2.5 transition-colors hover:bg-accent/50">
                    {!n.read ? <span className="mt-1.5 size-2 shrink-0 rounded-full bg-copper" aria-hidden /> : <span className="mt-1.5 size-2 shrink-0 rounded-full bg-border" aria-hidden />}
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium">{n.title}</p>
                      <p className="line-clamp-1 text-xs text-muted-foreground">{n.body}</p>
                    </div>
                    <Badge variant="outline" className="shrink-0">{n.category}</Badge>
                  </div>
                ))
              )}
            </CardContent>
          </Card>
        </Reveal>
      </div>

      <Reveal as="section" delay={160}>
        <UpcomingSessions />
      </Reveal>
    </div>
  );
}
