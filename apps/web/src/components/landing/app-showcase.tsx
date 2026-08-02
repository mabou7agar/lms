"use client";

import {
  LayoutDashboard, GraduationCap, Radio, Award, BarChart3, Flame, Play, ArrowUpRight,
} from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import type { Locale } from "@/config/theme";

type L = { en: string; ar: string };
const t = (v: L, l: Locale) => v[l] ?? v.en;

const NAV: { icon: typeof LayoutDashboard; label: L; active?: boolean }[] = [
  { icon: LayoutDashboard, label: { en: "Dashboard", ar: "الرئيسية" }, active: true },
  { icon: GraduationCap, label: { en: "My learning", ar: "تعلّمي" } },
  { icon: Radio, label: { en: "Live", ar: "مباشر" } },
  { icon: Award, label: { en: "Certificates", ar: "الشهادات" } },
  { icon: BarChart3, label: { en: "Analytics", ar: "التحليلات" } },
];

const BARS = [42, 58, 35, 70, 52, 84, 61];

/**
 * Composed learner-dashboard preview — a realistic, static approximation of the authenticated
 * product (sidebar, greeting, continue-learning, live session, progress, weekly activity) built
 * from real tokens. Not a generic illustration. RTL-safe, static (reduced-motion friendly).
 */
export function AppShowcase({ className }: { className?: string }) {
  const { locale } = useI18n();

  return (
    <div className={`overflow-hidden rounded-2xl border border-border/70 bg-card shadow-2xl shadow-primary/15 ring-1 ring-black/[0.03] ${className ?? ""}`} aria-hidden>
      {/* Window chrome */}
      <div className="flex items-center gap-2 border-b border-border/70 bg-surface/80 px-4 py-2.5">
        <span className="flex gap-1.5">
          <span className="size-2.5 rounded-full bg-destructive/70" />
          <span className="size-2.5 rounded-full bg-warning/80" />
          <span className="size-2.5 rounded-full bg-success/70" />
        </span>
        <span className="ms-2 truncate text-[0.7rem] font-medium text-muted-foreground">app.helbaron.academy / dashboard</span>
      </div>

      <div className="grid grid-cols-[auto_1fr]">
        {/* Sidebar */}
        <nav className="hidden w-40 flex-col gap-1 border-e border-border/70 bg-surface/50 p-3 sm:flex">
          <span className="mb-2 flex items-center gap-2 px-2">
            <span className="grid size-6 place-items-center rounded-md bg-primary font-serif text-[0.7rem] font-bold text-primary-foreground">H</span>
            <span className="font-serif text-sm font-semibold">HElbaron</span>
          </span>
          {NAV.map((n, i) => (
            <span key={i} className={`flex items-center gap-2 rounded-lg px-2 py-1.5 text-[0.72rem] ${n.active ? "bg-card font-semibold text-primary shadow-sm ring-1 ring-border/70" : "text-muted-foreground"}`}>
              <n.icon className="size-3.5" /> {t(n.label, locale)}
            </span>
          ))}
        </nav>

        {/* Main */}
        <div className="p-4">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-[0.65rem] font-medium text-muted-foreground">{t({ en: "Welcome back", ar: "أهلًا بعودتك" }, locale)}</p>
              <p className="font-serif text-sm font-semibold text-foreground">{t({ en: "Let's continue, Salma", ar: "لنكمل، سلمى" }, locale)}</p>
            </div>
            <span className="inline-flex items-center gap-1 rounded-full bg-copper/10 px-2 py-1 text-[0.65rem] font-semibold text-copper">
              <Flame className="size-3.5" /> {t({ en: "12-day streak", ar: "12 يومًا متتاليًا" }, locale)}
            </span>
          </div>

          {/* Stat row */}
          <div className="mt-3 grid grid-cols-3 gap-2">
            {[
              { v: "68%", l: { en: "Avg. progress", ar: "متوسط التقدّم" } as L },
              { v: "9", l: { en: "In progress", ar: "قيد التقدّم" } as L },
              { v: "4", l: { en: "Certificates", ar: "شهادات" } as L },
            ].map((s, i) => (
              <div key={i} className="rounded-lg border border-border/60 bg-surface/60 px-2.5 py-2">
                <p className="font-serif text-base font-bold text-primary">{s.v}</p>
                <p className="text-[0.6rem] text-muted-foreground">{t(s.l, locale)}</p>
              </div>
            ))}
          </div>

          {/* Continue learning */}
          <div className="mt-3 flex items-center gap-3 rounded-xl border border-border/70 bg-surface/40 p-2.5">
            <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-primary text-primary-foreground">
              <Play className="size-4 translate-x-px fill-current" />
            </span>
            <div className="min-w-0 flex-1">
              <p className="truncate text-[0.72rem] font-semibold text-foreground">{t({ en: "Business AI · Prompt patterns", ar: "الذكاء الاصطناعي · أنماط التوجيه" }, locale)}</p>
              <div className="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                <div className="h-full rounded-full bg-copper" style={{ width: "64%" }} />
              </div>
            </div>
            <span className="text-[0.6rem] font-semibold tabular-nums text-muted-foreground">64%</span>
          </div>

          <div className="mt-3 grid grid-cols-2 gap-2">
            {/* Upcoming live */}
            <div className="rounded-xl border border-border/70 bg-card p-2.5">
              <span className="inline-flex items-center gap-1 text-[0.6rem] font-semibold uppercase tracking-wide text-destructive">
                <span className="size-1.5 rounded-full bg-destructive" /> {t({ en: "Live in 2h", ar: "مباشر خلال ساعتين" }, locale)}
              </span>
              <p className="mt-1 text-[0.7rem] font-semibold leading-tight text-foreground">{t({ en: "Cohort session: GTM in MENA", ar: "جلسة الفوج: دخول سوق المنطقة" }, locale)}</p>
              <span className="mt-1.5 inline-flex items-center gap-0.5 text-[0.6rem] font-semibold text-primary">
                {t({ en: "Join", ar: "انضم" }, locale)} <ArrowUpRight className="size-3 rtl:rotate-90" />
              </span>
            </div>
            {/* Weekly activity */}
            <div className="rounded-xl border border-border/70 bg-card p-2.5">
              <p className="text-[0.6rem] font-semibold uppercase tracking-wide text-muted-foreground">{t({ en: "This week", ar: "هذا الأسبوع" }, locale)}</p>
              <div className="mt-2 flex h-9 items-end gap-1">
                {BARS.map((b, i) => (
                  <span key={i} className={`flex-1 rounded-sm ${i === 5 ? "bg-copper" : "bg-primary/25"}`} style={{ height: `${b}%` }} />
                ))}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
