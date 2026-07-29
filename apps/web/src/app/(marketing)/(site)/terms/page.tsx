import type { Metadata } from "next";
import { cache } from "react";
import { LegalPage } from "@/components/marketing/legal-page";
import { CmsPage } from "@/components/marketing/cms-page";
import { getStaticPage, type StaticPage } from "@/lib/pages/api";
import { buildPageMetadata, pageJsonLd } from "@/lib/pages/metadata";
import { jsonLdScript } from "@/lib/seo/json-ld";

const SLUG = "terms";

/** Deduped CMS fetch shared by generateMetadata and the page render. Null ⇒ hardcoded fallback. */
const loadPage = cache(async (): Promise<StaticPage | null> => getStaticPage(SLUG));

/** Built-in metadata used when the CMS record is absent/unreachable (URL never breaks). */
const fallbackMetadata: Metadata = {
  title: "Terms of Service",
  description: "The terms governing your use of HElbaron courses, cohorts, and services.",
  alternates: { canonical: "/terms" },
};

export async function generateMetadata(): Promise<Metadata> {
  const page = await loadPage();
  return page ? buildPageMetadata(page, "/terms") : fallbackMetadata;
}

/** The original hardcoded Terms content — preserved verbatim as the fallback. */
function TermsFallback() {
  return (
    <LegalPage
      title={{ en: "Terms of Service", ar: "شروط الخدمة" }}
      intro={{ en: "The terms that govern your use of HElbaron courses, cohorts, workshops, enterprise training, and advisory.", ar: "الشروط التي تحكم استخدامك لدورات وأفواج وورش وتدريب واستشارات HElbaron." }}
      sections={[
        { h: { en: "Using the platform", ar: "استخدام المنصة" }, p: { en: "Your account is personal. Content is licensed for your own learning and may not be redistributed.", ar: "حسابك شخصي. المحتوى مرخّص لتعلّمك الشخصي ولا يجوز إعادة توزيعه." } },
        { h: { en: "Payments & refunds", ar: "المدفوعات والاسترداد" }, p: { en: "Fees are shown before purchase. Refund eligibility depends on the program and is described at checkout.", ar: "تُعرض الرسوم قبل الشراء. تعتمد أهلية الاسترداد على البرنامج وتُوضّح عند الدفع." } },
        { h: { en: "Enterprise agreements", ar: "اتفاقيات المؤسسات" }, p: { en: "B2B / B2G engagements are governed by a separate signed agreement in addition to these terms.", ar: "تخضع مشاريع المؤسسات والحكومات لاتفاقية موقّعة منفصلة إضافةً لهذه الشروط." } },
      ]}
    />
  );
}

export default async function TermsPage() {
  const page = await loadPage();
  if (!page) return <TermsFallback />;

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
