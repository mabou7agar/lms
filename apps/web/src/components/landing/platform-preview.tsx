"use client";

import { Play, CheckCircle2, Lock, FileText, Award, TrendingUp } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { Locale } from "@/config/theme";

/**
 * Composed LMS product preview for the hero — NOT a generic illustration. It renders a realistic,
 * static approximation of the learner experience (course player, curriculum, progress, up-next)
 * using the real design tokens and the same visual language as the authenticated product, so the
 * marketing hero exposes the actual platform. Pure CSS/SVG (no images), RTL-safe via logical
 * properties, and static (no motion of its own) so it is reduced-motion friendly.
 */

type L = { en: string; ar: string };
const t = (v: L, l: Locale) => v[l] ?? v.en;

const COURSE: L = { en: "Business AI for Decision Makers", ar: "الذكاء الاصطناعي للأعمال لصنّاع القرار" };
const MODULE: L = { en: "Module 3 · Applied Prompting", ar: "الوحدة 3 · التطبيق العملي" };
const LESSONS: { title: L; state: "done" | "active" | "locked"; meta: L }[] = [
  { title: { en: "Framing the decision", ar: "صياغة القرار" }, state: "done", meta: { en: "8:20", ar: "8:20" } },
  { title: { en: "Prompt patterns that work", ar: "أنماط التوجيه الفعّالة" }, state: "active", meta: { en: "12:04", ar: "12:04" } },
  { title: { en: "Evaluating model output", ar: "تقييم مخرجات النموذج" }, state: "locked", meta: { en: "9:47", ar: "9:47" } },
  { title: { en: "From insight to action", ar: "من الرؤية إلى التنفيذ" }, state: "locked", meta: { en: "6:15", ar: "6:15" } },
];

export function PlatformPreview({ className }: { className?: string }) {
  const { locale } = useI18n();
  const pct = 64;

  return (
    <div className={`relative ${className ?? ""}`} aria-hidden>
      {/* Main product panel */}
      <div className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-2xl shadow-primary/15 ring-1 ring-black/[0.03]">
        {/* App top bar */}
        <div className="flex items-center gap-2 border-b border-border/70 bg-surface/80 px-4 py-2.5">
          <span className="flex gap-1.5">
            <span className="size-2.5 rounded-full bg-destructive/70" />
            <span className="size-2.5 rounded-full bg-warning/80" />
            <span className="size-2.5 rounded-full bg-success/70" />
          </span>
          <span className="ms-2 truncate text-[0.7rem] font-medium text-muted-foreground">
            app.helbaron.academy / learn
          </span>
        </div>

        <div className="grid grid-cols-[1fr] sm:grid-cols-[1.55fr_1fr]">
          {/* Player column */}
          <div className="p-4">
            {/* Video surface */}
            <div className="relative aspect-video overflow-hidden rounded-xl bg-primary">
              <div className="absolute inset-0 bg-[radial-gradient(120%_120%_at_20%_0%,oklch(0.42_0.05_185)_0%,oklch(0.30_0.04_190)_70%)]" />
              <div className="absolute inset-x-4 bottom-3">
                <div className="h-1.5 w-full overflow-hidden rounded-full bg-primary-foreground/20">
                  <div className="h-full rounded-full bg-copper" style={{ width: "38%" }} />
                </div>
              </div>
              <span className="absolute inset-0 grid place-items-center">
                <span className="grid size-12 place-items-center rounded-full bg-card/95 text-primary shadow-lg">
                  <Play className="size-5 translate-x-0.5 fill-current" />
                </span>
              </span>
              <span className="absolute start-3 top-3 rounded-md bg-card/90 px-2 py-0.5 text-[0.6rem] font-semibold uppercase tracking-wide text-primary">
                {t({ en: "Live preview", ar: "معاينة" }, locale)}
              </span>
            </div>

            <p className="mt-3 text-[0.7rem] font-semibold uppercase tracking-[0.14em] text-copper">
              {t(MODULE, locale)}
            </p>
            <h3 className="mt-1 font-serif text-base font-semibold leading-tight text-foreground">
              {t(COURSE, locale)}
            </h3>

            {/* Meta chips */}
            <div className="mt-3 flex flex-wrap gap-1.5">
              {[
                { icon: FileText, label: { en: "28 lessons", ar: "28 درسًا" } as L },
                { icon: Award, label: { en: "Certificate", ar: "شهادة" } as L },
                { icon: TrendingUp, label: { en: "Intermediate", ar: "متوسط" } as L },
              ].map(({ icon: Icon, label }, i) => (
                <span key={i} className="inline-flex items-center gap-1 rounded-full border border-border/70 bg-surface px-2 py-1 text-[0.65rem] font-medium text-muted-foreground">
                  <Icon className="size-3" /> {t(label, locale)}
                </span>
              ))}
            </div>
          </div>

          {/* Curriculum column */}
          <div className="border-t border-border/70 bg-surface/50 p-4 sm:border-s sm:border-t-0">
            <div className="flex items-center justify-between">
              <p className="text-[0.7rem] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                {t({ en: "Curriculum", ar: "المنهج" }, locale)}
              </p>
              <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[0.6rem] font-bold text-primary">
                {pct}%
              </span>
            </div>

            <ul className="mt-3 space-y-1.5">
              {LESSONS.map((l, i) => {
                const active = l.state === "active";
                return (
                  <li
                    key={i}
                    className={`flex items-center gap-2 rounded-lg px-2 py-1.5 ${
                      active ? "bg-card shadow-sm ring-1 ring-copper/30" : ""
                    }`}
                  >
                    {l.state === "done" ? (
                      <CheckCircle2 className="size-3.5 shrink-0 text-success" />
                    ) : l.state === "active" ? (
                      <span className="grid size-3.5 shrink-0 place-items-center rounded-full bg-copper text-copper-foreground">
                        <Play className="size-2 translate-x-px fill-current" />
                      </span>
                    ) : (
                      <Lock className="size-3.5 shrink-0 text-muted-foreground/60" />
                    )}
                    <span className={`flex-1 truncate text-[0.72rem] ${active ? "font-semibold text-foreground" : "text-muted-foreground"}`}>
                      {t(l.title, locale)}
                    </span>
                    <span className="text-[0.6rem] tabular-nums text-muted-foreground">{t(l.meta, locale)}</span>
                  </li>
                );
              })}
            </ul>
          </div>
        </div>
      </div>

      {/* Floating progress card */}
      <div className="absolute -bottom-5 -start-4 hidden w-44 rounded-xl border border-border/70 bg-card p-3 shadow-xl shadow-primary/10 sm:block">
        <div className="flex items-center gap-3">
          <span className="relative grid size-11 place-items-center">
            <svg viewBox="0 0 36 36" className="size-11 -rotate-90">
              <circle cx="18" cy="18" r="15.5" fill="none" stroke="var(--muted)" strokeWidth="3.5" />
              <circle
                cx="18" cy="18" r="15.5" fill="none" stroke="var(--copper)" strokeWidth="3.5"
                strokeLinecap="round" strokeDasharray={`${(pct / 100) * 97.4} 97.4`}
              />
            </svg>
            <span className="absolute text-[0.6rem] font-bold text-foreground">{pct}%</span>
          </span>
          <div>
            <p className="text-[0.6rem] font-semibold uppercase tracking-wide text-copper">
              {t({ en: "Your path", ar: "مسارك" }, locale)}
            </p>
            <p className="text-[0.7rem] font-medium leading-tight text-foreground">
              {t({ en: "9 of 14 lessons", ar: "9 من 14 درسًا" }, locale)}
            </p>
          </div>
        </div>
      </div>

      {/* Floating certificate card */}
      <div className="absolute -end-3 -top-4 hidden items-center gap-2 rounded-xl border border-border/70 bg-card px-3 py-2 shadow-xl shadow-primary/10 lg:flex">
        <span className="grid size-8 place-items-center rounded-lg bg-gold/15 text-gold">
          <Award className="size-4" />
        </span>
        <div>
          <p className="text-[0.7rem] font-semibold leading-tight text-foreground">
            {t({ en: "Verified certificate", ar: "شهادة موثّقة" }, locale)}
          </p>
          <p className="text-[0.6rem] text-muted-foreground">
            {t({ en: "On completion", ar: "عند الإتمام" }, locale)}
          </p>
        </div>
      </div>
    </div>
  );
}
