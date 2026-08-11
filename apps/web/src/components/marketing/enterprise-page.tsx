"use client";

import { useEffect, useRef } from "react";
import Link from "next/link";
import {
  Building2, Users, BarChart3, GraduationCap, CalendarClock, ClipboardCheck, Award,
  ShieldCheck, ChevronRight, ArrowRight,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Button } from "@/components/ui/button";
import { Reveal } from "@/components/landing/reveal";
import { messaging, localized } from "@/config/messaging";
import { track } from "@/lib/analytics/track";
import { EnterpriseLeadForm } from "@/components/marketing/enterprise-lead-form";

/** In-page anchor to the real enterprise-lead form (public POST /api/v1/public/leads). */
const REQUEST_DEMO = "#request-demo";

const T = {
  eyebrow: { en: "For organizations", ar: "للمؤسسات" },
  title: {
    en: "Run credible, Arabic-first learning across your whole organization.",
    ar: "أدِر تعلّمًا موثوقًا وعربيًا أولًا عبر مؤسستك بالكامل.",
  },
  lead: {
    en: "Administer learners at scale, deliver self-paced courses and live cohorts, and prove completion with verifiable certificates — in Arabic and English.",
    ar: "أدِر المتعلّمين على نطاق واسع، وقدّم دورات ذاتية ومجموعات مباشرة، وأثبِت الإتمام بشهادات قابلة للتحقّق — بالعربية والإنجليزية.",
  },
  primary: { en: "Request a demo", ar: "اطلب عرضًا توضيحيًا" },
  secondary: { en: "Compare platforms", ar: "قارن المنصّات" },
  targetsTitle: { en: "Built for", ar: "مصمّمة لـ" },
  capsTitle: { en: "What your team gets", ar: "ما يحصل عليه فريقك" },
  securityTitle: { en: "Security & operations", ar: "الأمان والتشغيل" },
  securityLead: {
    en: "Operational credibility grounded in how the platform is built — not marketing badges.",
    ar: "مصداقية تشغيلية مبنية على طريقة بناء المنصّة — لا شارات تسويقية.",
  },
  howTitle: { en: "How we get you live", ar: "كيف نُطلقك" },
  faqTitle: { en: "Enterprise FAQ", ar: "أسئلة المؤسسات الشائعة" },
  finalTitle: { en: "Talk to our team", ar: "تحدّث إلى فريقنا" },
  finalLead: {
    en: "Tell us about your program and we'll help you scope it.",
    ar: "أخبِرنا عن برنامجك وسنساعدك في تحديد نطاقه.",
  },
} as const;

const TARGETS: { icon: LucideIcon; label: { en: string; ar: string } }[] = [
  { icon: Building2, label: { en: "Companies & enterprise L&D", ar: "الشركات وفرق التعلّم والتطوير" } },
  { icon: GraduationCap, label: { en: "Training academies & centers", ar: "أكاديميات ومراكز التدريب" } },
  { icon: Users, label: { en: "Government & public-sector programs", ar: "برامج القطاع الحكومي والعام" } },
];

const CAPS: { icon: LucideIcon; title: { en: string; ar: string }; body: { en: string; ar: string } }[] = [
  { icon: Building2, title: { en: "Organization & member management", ar: "إدارة المؤسسة والأعضاء" }, body: { en: "Structure teams and manage members from one administrative surface.", ar: "نظّم الفِرق وأدِر الأعضاء من واجهة إدارية واحدة." } },
  { icon: Users, title: { en: "Learner administration", ar: "إدارة المتعلّمين" }, body: { en: "Enroll and administer learners across programs at scale.", ar: "سجّل وأدِر المتعلّمين عبر البرامج على نطاق واسع." } },
  { icon: BarChart3, title: { en: "Reporting & analytics", ar: "التقارير والتحليلات" }, body: { en: "Track progress and completion with built-in reporting.", ar: "تابِع التقدّم والإتمام عبر تقارير مدمجة." } },
  { icon: GraduationCap, title: { en: "Custom academies", ar: "أكاديميات مخصّصة" }, body: { en: "Publish your own branded catalog of courses and programs.", ar: "انشر كتالوجك الخاص من الدورات والبرامج بهويتك." } },
  { icon: CalendarClock, title: { en: "Cohorts & workshops", ar: "المجموعات والورش" }, body: { en: "Run scheduled, instructor-led cohorts alongside self-paced courses.", ar: "أدِر مجموعات مجدولة بقيادة مدرّب إلى جانب الدورات الذاتية." } },
  { icon: ClipboardCheck, title: { en: "Assignments & assessments", ar: "الواجبات والاختبارات" }, body: { en: "Evaluate learners with assignments, assessments, and a gradebook.", ar: "قيّم المتعلّمين بالواجبات والاختبارات وسجلّ الدرجات." } },
  { icon: Award, title: { en: "Verifiable certificates", ar: "شهادات قابلة للتحقّق" }, body: { en: "Issue certificates learners and employers can verify.", ar: "أصدِر شهادات يمكن للمتعلّمين وأصحاب العمل التحقّق منها." } },
  { icon: ShieldCheck, title: { en: "Role-based access", ar: "وصول قائم على الأدوار" }, body: { en: "Granular roles and permissions govern who can do what.", ar: "أدوار وصلاحيات دقيقة تحكم من يفعل ماذا." } },
];

// Grounded in verified repository behavior — NOT compliance certifications.
const SECURITY: { en: string; ar: string }[] = [
  { en: "Role-based access control with granular permissions.", ar: "تحكّم في الوصول قائم على الأدوار بصلاحيات دقيقة." },
  { en: "Multi-factor authentication available for administrator access.", ar: "مصادقة متعدّدة العوامل متاحة لوصول المسؤولين." },
  { en: "Tenant isolation so each organization's data stays separate.", ar: "عزل المستأجرين بحيث تبقى بيانات كل مؤسسة منفصلة." },
  { en: "Audit logging of administrative actions.", ar: "تسجيل تدقيقي للإجراءات الإدارية." },
];

const STEPS: { title: { en: string; ar: string }; body: { en: string; ar: string } }[] = [
  { title: { en: "Scope", ar: "تحديد النطاق" }, body: { en: "We map your program, audience, and success measures.", ar: "نرسم برنامجك وجمهورك ومقاييس نجاحك." } },
  { title: { en: "Set up", ar: "الإعداد" }, body: { en: "Your organization, teams, and catalog are configured.", ar: "تُهيّأ مؤسستك وفِرقك وكتالوجك." } },
  { title: { en: "Launch & report", ar: "الإطلاق والتقارير" }, body: { en: "Learners start, and you report on completion and outcomes.", ar: "يبدأ المتعلّمون، وترفع تقارير الإتمام والنتائج." } },
];

const FAQS: { q: { en: string; ar: string }; a: { en: string; ar: string } }[] = [
  { q: { en: "Can we administer many learners and teams?", ar: "هل يمكننا إدارة عدد كبير من المتعلّمين والفِرق؟" }, a: { en: "Yes — organization and member administration is built in, with reporting.", ar: "نعم — إدارة المؤسسة والأعضاء مدمجة، مع التقارير." } },
  { q: { en: "Do you support Arabic for the whole experience?", ar: "هل تدعمون العربية للتجربة بالكامل؟" }, a: { en: "Arabic is first-class with full right-to-left support across the product.", ar: "العربية من الدرجة الأولى مع دعم كامل للاتجاه من اليمين إلى اليسار عبر المنتج." } },
  { q: { en: "How is pricing determined?", ar: "كيف يُحدَّد التسعير؟" }, a: { en: "Enterprise programs are scoped and quoted to your size and needs — talk to our team.", ar: "تُحدَّد برامج المؤسسات وتُسعَّر حسب حجمك واحتياجك — تحدّث إلى فريقنا." } },
  { q: { en: "Do you claim ISO or SOC 2 certification?", ar: "هل تدّعون اعتماد ISO أو SOC 2؟" }, a: { en: "No. We describe how the platform is actually built (roles, MFA, tenant isolation, audit logs) and do not claim certifications we don't hold.", ar: "لا. نصف كيف بُنيت المنصّة فعليًا (الأدوار، المصادقة متعدّدة العوامل، عزل المستأجرين، سجلّات التدقيق) ولا نَدّعي اعتمادات لا نملكها." } },
];

export function EnterprisePage() {
  const { locale } = useI18n();
  const fired = useRef(false);
  useEffect(() => {
    if (fired.current) return;
    fired.current = true;
    track("page_view", { locale, path: "/enterprise" });
  }, [locale]);

  const onPrimary = () => track("enterprise_demo_started", { locale, path: "/enterprise" });
  const onSecondary = () => track("secondary_cta_clicked", { locale, intent: "secondary", to: "/compare" });

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
            <Button asChild size="lg"><Link href={REQUEST_DEMO} onClick={onPrimary}>{T.primary[locale]}<ArrowRight className="size-4 rtl:-scale-x-100" aria-hidden /></Link></Button>
            <Button asChild size="lg" variant="outline"><Link href="/compare" onClick={onSecondary}>{T.secondary[locale]}</Link></Button>
          </div>
        </Reveal>
      </section>

      {/* Problem → promise (from messaging) */}
      <section aria-labelledby="ent-problem" className="grid gap-4 sm:grid-cols-2">
        <Reveal>
          <div className="h-full rounded-2xl border border-border/70 bg-card p-6">
            <h2 id="ent-problem" className="font-serif text-lg font-semibold">{localized(messaging.problem, locale)}</h2>
          </div>
        </Reveal>
        <Reveal>
          <div className="h-full rounded-2xl border border-primary/25 bg-primary/[0.04] p-6">
            <p className="font-serif text-lg font-semibold text-primary">{localized(messaging.promise, locale)}</p>
          </div>
        </Reveal>
      </section>

      {/* Target organizations */}
      <section aria-labelledby="ent-targets">
        <Reveal><h2 id="ent-targets" className="font-serif text-2xl font-semibold">{T.targetsTitle[locale]}</h2></Reveal>
        <ul className="mt-6 grid gap-3 sm:grid-cols-3">
          {TARGETS.map(({ icon: Icon, label }, i) => (
            <Reveal key={i} as="li" className="flex items-center gap-3 rounded-xl border border-border/60 bg-surface/40 p-4">
              <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><Icon className="size-4" aria-hidden /></span>
              <span className="text-sm font-medium">{label[locale]}</span>
            </Reveal>
          ))}
        </ul>
      </section>

      {/* Capabilities */}
      <section aria-labelledby="ent-caps">
        <Reveal><h2 id="ent-caps" className="font-serif text-2xl font-semibold">{T.capsTitle[locale]}</h2></Reveal>
        <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {CAPS.map(({ icon: Icon, title, body }, i) => (
            <Reveal key={i}>
              <div className="h-full rounded-2xl border border-border/70 bg-card p-5">
                <span className="grid size-9 place-items-center rounded-lg bg-copper/10 text-copper"><Icon className="size-5" aria-hidden /></span>
                <h3 className="mt-3 font-semibold">{title[locale]}</h3>
                <p className="mt-1 text-sm text-muted-foreground">{body[locale]}</p>
              </div>
            </Reveal>
          ))}
        </div>
      </section>

      {/* Security & operations */}
      <section aria-labelledby="ent-sec" className="rounded-3xl border border-border/70 bg-surface/40 p-8">
        <Reveal>
          <h2 id="ent-sec" className="font-serif text-2xl font-semibold">{T.securityTitle[locale]}</h2>
          <p className="mt-2 text-sm text-muted-foreground">{T.securityLead[locale]}</p>
        </Reveal>
        <ul className="mt-6 grid gap-3 sm:grid-cols-2">
          {SECURITY.map((s, i) => (
            <Reveal key={i} as="li" className="flex items-start gap-3 text-sm">
              <ShieldCheck className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden />
              <span>{s[locale]}</span>
            </Reveal>
          ))}
        </ul>
      </section>

      {/* Implementation */}
      <section aria-labelledby="ent-how">
        <Reveal><h2 id="ent-how" className="font-serif text-2xl font-semibold">{T.howTitle[locale]}</h2></Reveal>
        <ol className="mt-6 grid gap-4 sm:grid-cols-3">
          {STEPS.map((s, i) => (
            <Reveal key={i} as="li" className="h-full rounded-2xl border border-border/70 bg-card p-6">
              <span className="grid size-8 place-items-center rounded-full bg-primary/10 font-serif text-sm font-semibold text-primary">{i + 1}</span>
              <h3 className="mt-3 font-semibold">{s.title[locale]}</h3>
              <p className="mt-1 text-sm text-muted-foreground">{s.body[locale]}</p>
            </Reveal>
          ))}
        </ol>
      </section>

      {/* FAQ */}
      <section aria-labelledby="ent-faq">
        <Reveal><h2 id="ent-faq" className="font-serif text-2xl font-semibold">{T.faqTitle[locale]}</h2></Reveal>
        <div className="mt-6 divide-y divide-border/60 rounded-2xl border border-border/70">
          {FAQS.map((f, i) => (
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

      {/* Final CTA — the real enterprise-lead form (public POST /api/v1/public/leads) */}
      <section id="request-demo" className="scroll-mt-24 rounded-3xl border border-primary/20 bg-primary/[0.04] p-8 sm:p-12">
        <Reveal>
          <h2 className="text-center font-serif text-2xl font-semibold sm:text-3xl">{T.finalTitle[locale]}</h2>
          <p className="mx-auto mt-2 max-w-xl text-center text-sm text-muted-foreground">{T.finalLead[locale]}</p>
          <div className="mx-auto mt-8 max-w-2xl">
            <EnterpriseLeadForm />
          </div>
        </Reveal>
      </section>
    </div>
  );
}

/** Visible enterprise FAQ, exported so the server page can emit matching FAQPage JSON-LD. */
export const ENTERPRISE_FAQ = FAQS;
