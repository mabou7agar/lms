import type { Metadata } from "next";
import { cache } from "react";
import { LegalPage } from "@/components/marketing/legal-page";
import { CmsPage } from "@/components/marketing/cms-page";
import { getStaticPage, type StaticPage } from "@/lib/pages/api";
import { buildPageMetadata, pageJsonLd } from "@/lib/pages/metadata";
import { jsonLdScript } from "@/lib/seo/json-ld";

const SLUG = "privacy";

/** Deduped CMS fetch shared by generateMetadata and the page render. Null ⇒ hardcoded fallback. */
const loadPage = cache(async (): Promise<StaticPage | null> => getStaticPage(SLUG));

/** Built-in metadata used when the CMS record is absent/unreachable (URL never breaks). */
const fallbackMetadata: Metadata = {
  title: "Privacy Policy",
  description: "How HElbaron collects, uses, and protects your information across our academy and services.",
  alternates: { canonical: "/privacy" },
};

export async function generateMetadata(): Promise<Metadata> {
  const page = await loadPage();
  return page ? buildPageMetadata(page, "/privacy") : fallbackMetadata;
}

/** The original hardcoded Privacy content — preserved verbatim as the fallback. */
function PrivacyFallback() {
  return (
    <LegalPage
      title={{ en: "Privacy Policy", ar: "سياسة الخصوصية" }}
      intro={{ en: "How HElbaron collects, uses, and protects your information across our academy and services.", ar: "كيف تجمع HElbaron معلوماتك وتستخدمها وتحميها عبر أكاديميتنا وخدماتنا." }}
      sections={[
        { h: { en: "Information we collect", ar: "المعلومات التي نجمعها" }, p: { en: "Account details, learning progress, and usage data needed to deliver courses, cohorts, and enterprise programs.", ar: "بيانات الحساب وتقدّم التعلّم وبيانات الاستخدام اللازمة لتقديم الدورات والأفواج وبرامج المؤسسات." } },
        { h: { en: "How we use it", ar: "كيف نستخدمها" }, p: { en: "To personalize learning, issue certificates, provide support, and improve the platform. We do not sell your data.", ar: "لتخصيص التعلّم وإصدار الشهادات وتقديم الدعم وتحسين المنصة. لا نبيع بياناتك." } },
        { h: { en: "Your rights", ar: "حقوقك" }, p: { en: "You may access, correct, export, or delete your data at any time by contacting our team.", ar: "يمكنك الوصول لبياناتك أو تصحيحها أو تصديرها أو حذفها في أي وقت بالتواصل مع فريقنا." } },
      ]}
    />
  );
}

export default async function PrivacyPage() {
  const page = await loadPage();
  if (!page) return <PrivacyFallback />;

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
