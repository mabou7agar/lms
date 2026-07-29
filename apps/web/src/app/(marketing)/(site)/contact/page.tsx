import type { Metadata } from "next";
import { cache } from "react";
import { ContentPage } from "@/components/marketing/content-page";
import { CmsPage } from "@/components/marketing/cms-page";
import { getStaticPage, type StaticPage } from "@/lib/pages/api";
import { buildPageMetadata, pageJsonLd } from "@/lib/pages/metadata";
import { jsonLdScript } from "@/lib/seo/json-ld";

const SLUG = "contact";

/** Deduped CMS fetch shared by generateMetadata and the page render. Null ⇒ hardcoded fallback. */
const loadPage = cache(async (): Promise<StaticPage | null> => getStaticPage(SLUG));

const description =
  "Get in touch with HElbaron — reach out about enterprise and government training, advisory engagements, or general questions.";

/** Built-in metadata used when the CMS record is absent/unreachable (URL never breaks). */
const fallbackMetadata: Metadata = {
  title: "Contact",
  description,
  alternates: { canonical: "/contact" },
  openGraph: { title: "Contact HElbaron", description, url: "/contact" },
};

export async function generateMetadata(): Promise<Metadata> {
  const page = await loadPage();
  return page ? buildPageMetadata(page, "/contact") : fallbackMetadata;
}

/** The original hardcoded Contact content — preserved verbatim as the fallback. */
function ContactFallback() {
  return (
    <ContentPage
      eyebrow={{ en: "CONTACT", ar: "تواصل معنا" }}
      title={{ en: "Let's", ar: "لنبدأ" }}
      emphasis={{ en: "talk.", ar: "الحديث." }}
      subtitle={{
        en: "Choose the route that fits your need. For business and consulting we'll connect you with the right team; for everything else, email us directly.",
        ar: "اختر المسار الذي يناسب احتياجك. للأعمال والاستشارات سنوصلك بالفريق المناسب؛ ولكل ما عدا ذلك، راسلنا مباشرة.",
      }}
      ctas={[
        { label: { en: "Enterprise training", ar: "تدريب المؤسسات" }, href: "/enterprise" },
        { label: { en: "Advisory & consulting", ar: "الاستشارات" }, href: "/advisory" },
      ]}
      cards={[
        {
          icon: "Building2",
          title: { en: "Enterprise & government", ar: "المؤسسات والحكومات" },
          body: {
            en: "For team training, seat-based plans, SSO/SCORM, and custom programs, start with our enterprise team.",
            ar: "لتدريب الفرق والخطط القائمة على المقاعد والدخول الموحّد وSCORM والبرامج المخصّصة، ابدأ مع فريق المؤسسات.",
          },
          highlight: true,
          cta: { label: { en: "Go to enterprise", ar: "إلى المؤسسات" }, href: "/enterprise" },
        },
        {
          icon: "Compass",
          title: { en: "Advisory & consulting", ar: "الاستشارات" },
          body: {
            en: "For strategy, operations, partnerships, and go-to-market engagements, reach HElbaron Advisory.",
            ar: "للاستراتيجية والعمليات والشراكات ودخول السوق، تواصل مع استشارات HElbaron.",
          },
          cta: { label: { en: "Go to advisory", ar: "إلى الاستشارات" }, href: "/advisory" },
        },
        {
          icon: "Mail",
          title: { en: "General questions", ar: "أسئلة عامة" },
          body: {
            en: "For anything else — courses, certificates, or partnerships — send us an email and we'll point you to the right place.",
            ar: "لأي شيء آخر — الدورات أو الشهادات أو الشراكات — راسلنا وسنوجّهك إلى المكان الصحيح.",
          },
          meta: { en: "hello@helbaron.academy", ar: "hello@helbaron.academy" },
          cta: { label: { en: "Email us", ar: "راسلنا" }, href: "mailto:hello@helbaron.academy" },
        },
      ]}
      sections={[
        {
          h: { en: "Where we are", ar: "أين نحن" },
          body: [
            {
              en: "HElbaron works across the region, with hubs in Cairo, Dubai, and Riyadh. Wherever you are, our courses and cohorts are available online in Arabic and English.",
              ar: "تعمل HElbaron عبر المنطقة، بمراكز في القاهرة ودبي والرياض. أينما كنت، دوراتنا وأفواجنا متاحة عبر الإنترنت بالعربية والإنجليزية.",
            },
          ],
        },
        {
          h: { en: "Already a learner?", ar: "متعلّم بالفعل؟" },
          body: [
            {
              en: "If you already have an account, sign in to manage your profile, track progress, and download or verify your certificates.",
              ar: "إن كان لديك حساب بالفعل، سجّل الدخول لإدارة ملفك ومتابعة تقدّمك وتنزيل شهاداتك أو التحقّق منها.",
            },
          ],
        },
      ]}
    />
  );
}

export default async function ContactPage() {
  const page = await loadPage();
  if (!page) return <ContactFallback />;

  const jsonLd = pageJsonLd(page);
  return (
    <>
      {jsonLd ? (
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: jsonLdScript(jsonLd) }}
        />
      ) : null}
      <CmsPage page={page} />
    </>
  );
}
