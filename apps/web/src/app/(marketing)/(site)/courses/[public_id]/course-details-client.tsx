"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import {
  ArrowLeft, GraduationCap, Languages, Star, Award, BookOpen, Layers, CheckCircle2, ArrowRight,
} from "lucide-react";
import { errorMessage } from "@/lib/api/errors";
import { useAuth } from "@/lib/auth/auth-context";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useCourse, useEnroll } from "@/lib/catalog/hooks";
import { QueryState } from "@/components/student/query-state";
import { ReviewsSection } from "@/components/community/reviews-section";
import { CourseCard } from "@/components/catalog/course-card";
import { CourseMedia } from "@/components/catalog/course-media";
import { VideoEmbed, hasEmbeddableVideo } from "@/components/media/video-embed";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { proxyMediaUrl } from "@/lib/media/proxy";
import { Reveal } from "@/components/landing/reveal";
import { toast } from "@/components/ui/toast";

export function CourseDetailsClient() {
  const { t, locale } = useI18n();
  const L = (en: string, ar: string) => (locale === "ar" ? ar : en);
  const params = useParams<{ public_id: string }>();
  const publicId = params.public_id;
  const { status } = useAuth();
  const query = useCourse(publicId);
  const enroll = useEnroll();
  const authed = status === "authenticated";

  const onEnroll = () =>
    enroll.mutate(publicId, {
      onSuccess: () => toast.success(t("catalog.course.enrolled")),
      onError: (e) => toast.error(errorMessage(e, t("common.error"))),
    });

  return (
    <div className="pb-24 lg:pb-4">
      <Button asChild variant="ghost" size="sm" className="mb-4">
        <Link href="/courses">
          <ArrowLeft className="size-4 rtl:rotate-180" aria-hidden /> {t("catalog.course.back")}
        </Link>
      </Button>

      <QueryState query={query}>
        {(course) => {
          const primaryCategory = course.categories[0]?.name;
          const cta = authed ? (
            <Button className="w-full shine relative overflow-hidden" size="lg" loading={enroll.isPending} onClick={onEnroll}>
              {t("catalog.course.enroll")}
              <ArrowRight className="size-4 rtl:rotate-180" aria-hidden />
            </Button>
          ) : (
            <Button asChild className="w-full" size="lg">
              <Link href={`/login?redirect=/courses/${course.id}`}>{t("catalog.course.signInToEnroll")}</Link>
            </Button>
          );

          return (
            <div className="space-y-16">
              {/* HERO */}
              <section className="relative overflow-hidden rounded-3xl border border-border/70 bg-card">
                <div className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(90%_120%_at_100%_-15%,oklch(0.42_0.05_185/0.10)_0%,transparent_55%)]" aria-hidden />
                <div className="pointer-events-none absolute inset-0 -z-10 opacity-40 [background-image:radial-gradient(var(--border)_1px,transparent_1px)] [background-size:22px_22px] [mask-image:radial-gradient(80%_80%_at_100%_0%,#000_0%,transparent_75%)]" aria-hidden />
                <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-copper/40 to-transparent" aria-hidden />

                <div className="relative grid items-center gap-10 p-8 sm:p-12 lg:grid-cols-[1.15fr_0.85fr]">
                  <Reveal>
                    {primaryCategory ? (
                      <div className="mb-4 inline-flex items-center gap-2 rounded-full border border-copper/25 bg-copper/[0.06] ps-2 pe-3 py-1 text-[0.7rem] font-semibold uppercase tracking-[0.16em] text-copper">
                        <span className="size-1.5 rounded-full bg-copper" aria-hidden />
                        {primaryCategory}
                      </div>
                    ) : null}
                    <div className="flex flex-wrap items-center gap-3">
                      <h1 className="text-display font-serif leading-[1.05] tracking-tight">{course.title}</h1>
                    </div>
                    {course.is_featured ? (
                      <Badge variant="warning" className="mt-3 gap-1">
                        <Star className="size-3" aria-hidden /> {t("catalog.course.featured")}
                      </Badge>
                    ) : null}
                    {course.subtitle ? (
                      <p className="mt-5 max-w-xl text-muted-foreground sm:text-lg">{course.subtitle}</p>
                    ) : null}

                    <div className="mt-6 flex flex-wrap gap-2">
                      {course.level ? (
                        <Badge variant="secondary" className="gap-1">
                          <GraduationCap className="size-3" aria-hidden /> {course.level.name}
                        </Badge>
                      ) : null}
                      {course.language ? (
                        <Badge variant="outline" className="gap-1">
                          <Languages className="size-3" aria-hidden /> {course.language.name}
                        </Badge>
                      ) : null}
                      {course.categories.map((c) => <Badge key={c.id} variant="secondary">{c.name}</Badge>)}
                    </div>

                    {course.trainers.length > 0 ? (
                      <div className="mt-7 flex items-center gap-3">
                        <div className="flex -space-x-2 rtl:space-x-reverse">
                          {course.trainers.slice(0, 4).map((tr) => (
                            <Avatar key={tr.id} className="size-9 border-2 border-card">
                              {tr.avatar_path ? <AvatarImage src={proxyMediaUrl(tr.avatar_path)} alt={tr.name} /> : null}
                              <AvatarFallback className="text-[0.7rem]">
                                {tr.name.split(" ").map((p) => p[0]).slice(0, 2).join("").toUpperCase()}
                              </AvatarFallback>
                            </Avatar>
                          ))}
                        </div>
                        <p className="text-sm text-muted-foreground">
                          {L("Taught by", "يقدّمها")}{" "}
                          <span className="font-medium text-foreground">
                            {course.trainers.map((tr) => tr.name).join(locale === "ar" ? "، " : ", ")}
                          </span>
                        </p>
                      </div>
                    ) : null}
                  </Reveal>

                  <Reveal delay={120}>
                    <div className="rounded-2xl border border-border/70 bg-surface/50 p-2 shadow-xl shadow-primary/10">
                      <div className="overflow-hidden rounded-xl">
                        <CourseMedia src={course.thumbnail_path} title={course.title} />
                      </div>
                    </div>
                  </Reveal>
                </div>
              </section>

              {/* PREVIEW — promo/trailer video (uploaded file OR external YouTube/Vimeo/Wistia/Loom/Dailymotion). */}
              {course.trailer_path && hasEmbeddableVideo(course.trailer_path) ? (
                <Reveal as="section">
                  <h2 className="text-h2 font-serif">{L("Preview", "معاينة")}</h2>
                  <div className="mt-4 h-px w-16 bg-copper/40" aria-hidden />
                  <div className="mt-6 overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm">
                    <VideoEmbed
                      url={course.trailer_path}
                      title={course.title}
                      poster={proxyMediaUrl(course.thumbnail_path)}
                    />
                  </div>
                </Reveal>
              ) : null}

              <div className="grid gap-10 lg:grid-cols-3">
                <div className="space-y-12 lg:col-span-2">
                  {/* ABOUT */}
                  {course.description ? (
                    <Reveal as="section">
                      <h2 className="text-h2 font-serif">{t("catalog.course.about")}</h2>
                      <div className="mt-4 h-px w-16 bg-copper/40" aria-hidden />
                      <p className="mt-5 whitespace-pre-line leading-relaxed text-muted-foreground">{course.description}</p>
                    </Reveal>
                  ) : null}

                  {/* INSTRUCTORS */}
                  {course.trainers.length > 0 ? (
                    <Reveal as="section">
                      <h2 className="text-h2 font-serif">{t("catalog.course.trainers")}</h2>
                      <div className="mt-4 h-px w-16 bg-copper/40" aria-hidden />
                      <div className="mt-6 grid gap-4 sm:grid-cols-2">
                        {course.trainers.map((tr) => (
                          <div key={tr.id} className="flex items-start gap-4 rounded-2xl border border-border bg-card p-5 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg">
                            <Avatar className="size-12">
                              {tr.avatar_path ? <AvatarImage src={proxyMediaUrl(tr.avatar_path)} alt={tr.name} /> : null}
                              <AvatarFallback>{tr.name.split(" ").map((p) => p[0]).slice(0, 2).join("").toUpperCase()}</AvatarFallback>
                            </Avatar>
                            <div>
                              <p className="font-serif text-lg font-semibold">{tr.name}</p>
                              {tr.headline ? <p className="mt-0.5 text-sm text-muted-foreground">{tr.headline}</p> : null}
                            </div>
                          </div>
                        ))}
                      </div>
                    </Reveal>
                  ) : null}

                  {/* TAGS */}
                  {course.tags.length > 0 ? (
                    <section>
                      <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">{t("catalog.course.tags")}</h2>
                      <div className="flex flex-wrap gap-1.5">
                        {course.tags.map((tag) => <Badge key={tag.id} variant="outline">{tag.name}</Badge>)}
                      </div>
                    </section>
                  ) : null}
                </div>

                {/* STICKY CONVERSION PANEL (desktop) */}
                <aside className="hidden lg:col-span-1 lg:block">
                  <div className="sticky top-24 space-y-4">
                    <div className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm">
                      <div className="p-2">
                        <div className="overflow-hidden rounded-xl">
                          <CourseMedia src={course.thumbnail_path} title={course.title} />
                        </div>
                      </div>
                      <div className="space-y-5 p-5">
                        <div>{cta}</div>
                        <ul className="space-y-3 text-sm">
                          {course.language ? (
                            <li className="flex items-center gap-3">
                              <Languages className="size-4 text-copper" aria-hidden />
                              <span className="text-muted-foreground">{L("Language", "اللغة")}:</span>
                              <span className="ms-auto font-medium">{course.language.name}</span>
                            </li>
                          ) : null}
                          {course.level ? (
                            <li className="flex items-center gap-3">
                              <BookOpen className="size-4 text-copper" aria-hidden />
                              <span className="text-muted-foreground">{L("Level", "المستوى")}:</span>
                              <span className="ms-auto font-medium">{course.level.name}</span>
                            </li>
                          ) : null}
                          {course.categories.length > 0 ? (
                            <li className="flex items-center gap-3">
                              <Layers className="size-4 text-copper" aria-hidden />
                              <span className="text-muted-foreground">{L("Track", "المسار")}:</span>
                              <span className="ms-auto font-medium">{course.categories[0].name}</span>
                            </li>
                          ) : null}
                          <li className="flex items-center gap-3">
                            <Award className="size-4 text-copper" aria-hidden />
                            <span className="font-medium">{L("Certificate of completion", "شهادة إتمام")}</span>
                          </li>
                        </ul>
                      </div>
                    </div>
                    {!authed ? (
                      <p className="px-1 text-center text-xs text-muted-foreground">
                        {L("Free account — enroll in seconds.", "حساب مجاني — سجّل خلال ثوانٍ.")}
                      </p>
                    ) : null}
                  </div>
                </aside>
              </div>

              {/* RELATED */}
              {course.related.length > 0 ? (
                <Reveal as="section">
                  <div className="flex items-end justify-between gap-4">
                    <h2 className="text-h2 font-serif">{t("catalog.course.related")}</h2>
                    <Button asChild variant="ghost" size="sm" className="text-copper">
                      <Link href="/courses">
                        {t("catalog.course.view")} <ArrowRight className="size-4 rtl:rotate-180" aria-hidden />
                      </Link>
                    </Button>
                  </div>
                  <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {course.related.map((c) => <CourseCard key={c.id} course={c} />)}
                  </div>
                </Reveal>
              ) : null}

              {/* REVIEWS — public aggregate + list; write form gated to signed-in learners */}
              <Reveal as="section">
                <ReviewsSection courseId={course.id} canReview={authed} isAuthenticated={authed} />
              </Reveal>
            </div>
          );
        }}
      </QueryState>

      {/* MOBILE STICKY CTA BAR */}
      <QueryState query={query}>
        {(course) => (
          <div className="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-card/95 p-3 shadow-[0_-4px_20px_rgba(0,0,0,0.06)] backdrop-blur lg:hidden">
            <div className="mx-auto flex max-w-2xl items-center gap-3">
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold">{course.title}</p>
                {course.level ? <p className="text-xs text-muted-foreground">{course.level.name}</p> : null}
              </div>
              {authed ? (
                <Button loading={enroll.isPending} onClick={onEnroll}>
                  {t("catalog.course.enroll")}
                </Button>
              ) : (
                <Button asChild>
                  <Link href={`/login?redirect=/courses/${course.id}`}>{t("catalog.course.signInToEnroll")}</Link>
                </Button>
              )}
            </div>
          </div>
        )}
      </QueryState>
    </div>
  );
}
