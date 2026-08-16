"use client";

import { useEffect, useRef } from "react";
import { PRICING_FAQ } from "./pricing-faq";
import Link from "next/link";
import { Gift, BookOpen, CalendarClock, Building2, Check, CircleDot, ChevronRight, ArrowRight } from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Button } from "@/components/ui/button";
import { Reveal } from "@/components/landing/reveal";
import { track } from "@/lib/analytics/track";

type L = { en: string; ar: string };

const T = {
  eyebrow: { en: "Pricing", ar: "الأسعار" },
  title: { en: "Pay for what you actually use.", ar: "ادفع مقابل ما تستخدمه فعلًا." },
  lead: {
    en: "No confusing tiers and no invented numbers. Start with free courses, buy individual courses at the price shown on each course, join live programs per program, or scope an enterprise rollout.",
    ar: "لا باقات مربكة ولا أرقام مُختلقة. ابدأ بالدورات المجانية، واشترِ الدورات الفردية بالسعر المعروض على كل دورة، وانضمّ إلى البرامج المباشرة لكل برنامج، أو حدّد نطاق نشر مؤسسي.",
  },
  browse: { en: "Browse courses", ar: "تصفّح الدورات" },
  talkEnterprise: { en: "Talk to enterprise", ar: "تحدّث مع فريق المؤسسات" },
  audienceTitle: { en: "Who is it for", ar: "لمن هذا" },
  modelsTitle: { en: "Ways to buy", ar: "طرق الشراء" },
  compareTitle: { en: "What's included", ar: "ما المُتضمَّن" },
  faqTitle: { en: "Pricing FAQ", ar: "أسئلة التسعير الشائعة" },
  colIndividual: { en: "Individual", ar: "فردي" },
  colOrg: { en: "Team / Enterprise", ar: "فريق / مؤسسة" },
} as const;

const AUDIENCES: { title: L; body: L }[] = [
  { title: { en: "Learners", ar: "المتعلّمون" }, body: { en: "Buy the courses you want, keep lifetime access to each.", ar: "اشترِ الدورات التي تريدها واحتفظ بوصول دائم لكلٍّ منها." } },
  { title: { en: "Academies & creators", ar: "الأكاديميات وصنّاع المحتوى" }, body: { en: "Publish and sell courses and run cohorts under your brand.", ar: "انشر وبِع الدورات وأدِر المجموعات بهويتك." } },
  { title: { en: "Organizations", ar: "المؤسسات" }, body: { en: "Administer learners and report on outcomes across teams.", ar: "أدِر المتعلّمين وارفع تقارير النتائج عبر الفِرق." } },
];

interface Model {
  slug: string;
  icon: LucideIcon;
  title: L;
  body: L;
  meta: L;
  cta: { label: L; href: string };
  highlight?: boolean;
}

const MODELS: Model[] = [
  {
    slug: "free",
    icon: Gift,
    title: { en: "Free courses", ar: "دورات مجانية" },
    body: { en: "Selected courses are free. Create an account and start learning right away.", ar: "دورات مختارة مجانية. أنشئ حسابًا وابدأ التعلّم فورًا." },
    meta: { en: "No payment required", ar: "بدون دفع" },
    cta: { label: { en: "Browse courses", ar: "تصفّح الدورات" }, href: "/courses" },
  },
  {
    slug: "per_course",
    icon: BookOpen,
    title: { en: "Per course", ar: "لكل دورة" },
    body: { en: "Paid courses are priced individually — the price is shown on each course page. Buy once, keep access.", ar: "الدورات المدفوعة مسعّرة فرديًا — يظهر السعر على صفحة كل دورة. ادفع مرة واحدة واحتفظ بالوصول." },
    meta: { en: "Price shown per course", ar: "السعر معروض لكل دورة" },
    highlight: true,
    cta: { label: { en: "See course prices", ar: "شاهد أسعار الدورات" }, href: "/courses" },
  },
  {
    slug: "cohorts",
    icon: CalendarClock,
    title: { en: "Cohorts & workshops", ar: "المجموعات والورش" },
    body: { en: "Live, instructor-led programs with limited seats are priced per program, not by subscription.", ar: "برامج مباشرة بقيادة مدرّب بمقاعد محدودة تُسعّر لكل برنامج، لا بالاشتراك." },
    meta: { en: "Priced per program", ar: "تُسعّر لكل برنامج" },
    cta: { label: { en: "See cohorts", ar: "شاهد المجموعات" }, href: "/cohorts" },
  },
  {
    slug: "enterprise",
    icon: Building2,
    title: { en: "Enterprise & organizations", ar: "المؤسسات والمنظمات" },
    body: { en: "Roll out learning across teams with administration and reporting. Programs are scoped and quoted to your needs.", ar: "وفّر التعلّم عبر الفِرق مع الإدارة والتقارير. تُحدَّد البرامج وتُسعَّر حسب احتياجك." },
    meta: { en: "Custom quote", ar: "عرض سعر مخصّص" },
    cta: { label: { en: "Request a quote", ar: "اطلب عرض سعر" }, href: "/enterprise" },
  },
];

type Cell = "yes" | "varies";
const COMPARE: { dim: L; individual: Cell; org: Cell }[] = [
  { dim: { en: "Self-paced courses", ar: "دورات ذاتية" }, individual: "yes", org: "yes" },
  { dim: { en: "Live cohorts & workshops", ar: "مجموعات مباشرة وورش" }, individual: "yes", org: "yes" },
  { dim: { en: "Verifiable certificates", ar: "شهادات قابلة للتحقّق" }, individual: "yes", org: "yes" },
  { dim: { en: "Bilingual Arabic/English + RTL", ar: "ثنائية اللغة عربي/إنجليزي + RTL" }, individual: "yes", org: "yes" },
  { dim: { en: "Organization administration", ar: "إدارة المؤسسة" }, individual: "varies", org: "yes" },
  { dim: { en: "Reporting & analytics", ar: "التقارير والتحليلات" }, individual: "varies", org: "yes" },
];

// FAQ data lives in the server-safe ./pricing-faq module (imported above) so the server page can emit
// FAQPage JSON-LD from the same source without importing this "use client" component.

function CompareBadge({ cell }: { cell: Cell }) {
  const { locale } = useI18n();
  if (cell === "yes") return <span className="inline-flex items-center gap-1.5 text-sm text-primary"><Check className="size-4" aria-hidden />{locale === "ar" ? "نعم" : "Yes"}</span>;
  return <span className="inline-flex items-center gap-1.5 text-sm text-copper"><CircleDot className="size-4" aria-hidden />{locale === "ar" ? "يختلف" : "Varies"}</span>;
}

export function PricingPage() {
  const { locale } = useI18n();
  const fired = useRef(false);
  useEffect(() => {
    if (fired.current) return;
    fired.current = true;
    track("pricing_viewed", { locale, path: "/pricing" });
  }, [locale]);

  return (
    <div className="space-y-16 py-2 sm:space-y-20">
      {/* Hero */}
      <section className="relative overflow-hidden rounded-3xl border border-border/70 bg-card p-8 sm:p-12">
        <div className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(90%_120%_at_100%_-10%,oklch(0.42_0.05_185/0.10)_0%,transparent_55%)]" aria-hidden />
        <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-copper/40 to-transparent" aria-hidden />
        <Reveal>
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-copper">{T.eyebrow[locale]}</p>
          <h1 className="mt-3 max-w-3xl font-serif text-3xl font-semibold tracking-tight sm:text-4xl">{T.title[locale]}</h1>
          <p className="mt-4 max-w-2xl text-muted-foreground">{T.lead[locale]}</p>
          <div className="mt-7 flex flex-wrap gap-3">
            <Button asChild size="lg"><Link href="/courses" onClick={() => track("primary_cta_clicked", { locale, intent: "primary", to: "/courses" })}>{T.browse[locale]}<ArrowRight className="size-4 rtl:-scale-x-100" aria-hidden /></Link></Button>
            <Button asChild size="lg" variant="outline"><Link href="/enterprise" onClick={() => track("secondary_cta_clicked", { locale, intent: "secondary", to: "/enterprise" })}>{T.talkEnterprise[locale]}</Link></Button>
          </div>
        </Reveal>
      </section>

      {/* Audience */}
      <section aria-labelledby="pr-aud">
        <Reveal><h2 id="pr-aud" className="font-serif text-2xl font-semibold">{T.audienceTitle[locale]}</h2></Reveal>
        <div className="mt-6 grid gap-4 sm:grid-cols-3">
          {AUDIENCES.map((a, i) => (
            <Reveal key={i}>
              <div className="h-full rounded-2xl border border-border/70 bg-card p-5">
                <h3 className="font-semibold">{a.title[locale]}</h3>
                <p className="mt-1 text-sm text-muted-foreground">{a.body[locale]}</p>
              </div>
            </Reveal>
          ))}
        </div>
      </section>

      {/* Purchase models */}
      <section aria-labelledby="pr-models">
        <Reveal><h2 id="pr-models" className="font-serif text-2xl font-semibold">{T.modelsTitle[locale]}</h2></Reveal>
        <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {MODELS.map((m) => {
            const Icon = m.icon;
            return (
              <Reveal key={m.slug}>
                <div className={`flex h-full flex-col rounded-2xl border p-6 ${m.highlight ? "border-primary/40 bg-primary/[0.03]" : "border-border/70 bg-card"}`}>
                  <span className="grid size-9 place-items-center rounded-lg bg-copper/10 text-copper"><Icon className="size-5" aria-hidden /></span>
                  <h3 className="mt-3 font-serif text-lg font-semibold">{m.title[locale]}</h3>
                  <p className="mt-1 flex-1 text-sm text-muted-foreground">{m.body[locale]}</p>
                  <p className="mt-3 text-sm font-medium text-foreground">{m.meta[locale]}</p>
                  <Button asChild variant={m.highlight ? "default" : "outline"} className="mt-4 w-full">
                    <Link href={m.cta.href} onClick={() => track("plan_selected", { locale, plan: m.slug })}>{m.cta.label[locale]}</Link>
                  </Button>
                </div>
              </Reveal>
            );
          })}
        </div>
      </section>

      {/* Capability comparison */}
      <section aria-labelledby="pr-compare">
        <Reveal><h2 id="pr-compare" className="font-serif text-2xl font-semibold">{T.compareTitle[locale]}</h2></Reveal>
        <div className="mt-6 overflow-x-auto rounded-2xl border border-border/70">
          <table className="w-full border-collapse text-start">
            <caption className="sr-only">{T.compareTitle[locale]}</caption>
            <thead>
              <tr className="bg-surface/60 text-sm">
                <th scope="col" className="p-4 text-start font-semibold"> </th>
                <th scope="col" className="p-4 text-start font-semibold">{T.colIndividual[locale]}</th>
                <th scope="col" className="p-4 text-start font-semibold text-primary">{T.colOrg[locale]}</th>
              </tr>
            </thead>
            <tbody>
              {COMPARE.map((row, i) => (
                <tr key={i} className="border-t border-border/60">
                  <th scope="row" className="p-4 text-start text-sm font-medium">{row.dim[locale]}</th>
                  <td className="p-4"><CompareBadge cell={row.individual} /></td>
                  <td className="p-4"><CompareBadge cell={row.org} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      {/* FAQ */}
      <section aria-labelledby="pr-faq">
        <Reveal><h2 id="pr-faq" className="font-serif text-2xl font-semibold">{T.faqTitle[locale]}</h2></Reveal>
        <div className="mt-6 divide-y divide-border/60 rounded-2xl border border-border/70">
          {PRICING_FAQ.map((f, i) => (
            <details key={i} className="group p-5">
              <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-medium">
                {f.q[locale]}
                <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-90 rtl:-scale-x-100 rtl:group-open:-rotate-90" aria-hidden />
              </summary>
              <p className="mt-3 text-sm text-muted-foreground">{f.a[locale]}</p>
            </details>
          ))}
        </div>
      </section>
    </div>
  );
}

