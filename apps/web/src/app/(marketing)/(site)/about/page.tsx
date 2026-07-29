import type { Metadata } from "next";
import { cache } from "react";
import { ContentPage } from "@/components/marketing/content-page";
import { CmsPage } from "@/components/marketing/cms-page";
import { getStaticPage, type StaticPage } from "@/lib/pages/api";
import { buildPageMetadata, pageJsonLd } from "@/lib/pages/metadata";
import { jsonLdScript } from "@/lib/seo/json-ld";

const SLUG = "about";

/** Deduped CMS fetch shared by generateMetadata and the page render. Null ⇒ hardcoded fallback. */
const loadPage = cache(async (): Promise<StaticPage | null> => getStaticPage(SLUG));

const description =
  "HElbaron is a bilingual professional academy built for the MENA region — practical courses, live cohorts, and verifiable certificates in Arabic and English.";

/** Built-in metadata used when the CMS record is absent/unreachable (URL never breaks). */
const fallbackMetadata: Metadata = {
  title: "About",
  description,
  alternates: { canonical: "/about" },
  openGraph: { title: "About HElbaron", description, url: "/about" },
};

export async function generateMetadata(): Promise<Metadata> {
  const page = await loadPage();
  return page ? buildPageMetadata(page, "/about") : fallbackMetadata;
}

/** The original hardcoded About content — preserved verbatim as the fallback. */
function AboutFallback() {
  return (
    <ContentPage
      eyebrow={{ en: "ABOUT HELBARON", ar: "عن HElbaron" }}
      title={{ en: "A bilingual academy", ar: "أكاديمية ثنائية اللغة" }}
      emphasis={{ en: "built for the region.", ar: "مصمّمة للمنطقة." }}
      subtitle={{
        en: "HElbaron exists to make high-quality, practical business education available in both Arabic and English — designed from the ground up for learners across the MENA region.",
        ar: "وُجدت HElbaron لإتاحة تعليم أعمال عملي وعالي الجودة بالعربية والإنجليزية معًا — مصمّمة من الأساس لمتعلّمي منطقة الشرق الأوسط وشمال أفريقيا.",
      }}
      ctas={[
        { label: { en: "Explore courses", ar: "استكشف الدورات" }, href: "/courses" },
        { label: { en: "Talk to us", ar: "تواصل معنا" }, href: "/contact" },
      ]}
      cards={[
        {
          icon: "Target",
          title: { en: "Our mission", ar: "مهمّتنا" },
          body: {
            en: "Help professionals and teams master the fundamentals and lead with confidence — with learning that respects their language and context.",
            ar: "مساعدة المحترفين والفرق على إتقان الأساسيات والقيادة بثقة — بتعليم يحترم لغتهم وسياقهم.",
          },
        },
        {
          icon: "Sparkles",
          title: { en: "How we teach", ar: "كيف نعلّم" },
          body: {
            en: "Practical, outcome-focused programs led by practitioners — courses, live cohorts, and workshops you can apply the next day.",
            ar: "برامج عملية تركّز على النتائج يقودها ممارسون — دورات وأفواج مباشرة وورش يمكنك تطبيقها في اليوم التالي.",
          },
        },
        {
          icon: "Languages",
          title: { en: "Bilingual by design", ar: "ثنائية اللغة بالتصميم" },
          body: {
            en: "The whole experience works in Arabic and English, with full right-to-left support — not a translation bolted on afterwards.",
            ar: "التجربة بأكملها تعمل بالعربية والإنجليزية مع دعم كامل للكتابة من اليمين إلى اليسار — لا ترجمة مُضافة لاحقًا.",
          },
        },
        {
          icon: "Award",
          title: { en: "Verifiable certificates", ar: "شهادات قابلة للتحقّق" },
          body: {
            en: "Finish a course and earn a certificate anyone can verify online through a unique verification code.",
            ar: "أكمل دورة واحصل على شهادة يمكن لأي شخص التحقّق منها عبر الإنترنت برمز تحقّق فريد.",
          },
        },
      ]}
      sections={[
        {
          h: { en: "Our story", ar: "قصّتنا" },
          body: [
            {
              en: "HElbaron started from a simple observation: ambitious professionals across the region were learning in a language that wasn't theirs, from material that didn't reflect their market. We set out to build an academy that treats Arabic and English as equals and puts practical, regional relevance first.",
              ar: "بدأت HElbaron من ملاحظة بسيطة: محترفون طموحون في المنطقة يتعلّمون بلغة ليست لغتهم ومن مواد لا تعكس سوقهم. فانطلقنا لبناء أكاديمية تعامل العربية والإنجليزية على قدم المساواة وتضع الملاءمة العملية والإقليمية أولًا.",
            },
          ],
        },
        {
          h: { en: "What we believe", ar: "ما نؤمن به" },
          body: [
            {
              en: "Great education is practical, honest, and accessible. We focus on skills people can use, we present our offering plainly, and we build for the languages and devices our learners actually use.",
              ar: "التعليم الجيّد عملي وصادق ومتاح. نركّز على مهارات يمكن للناس استخدامها، ونعرض ما نقدّمه بوضوح، ونبني للّغات والأجهزة التي يستخدمها متعلّمونا فعلًا.",
            },
            {
              en: "We are an independent academy. We describe our programs honestly and do not claim external accreditation we do not hold.",
              ar: "نحن أكاديمية مستقلّة. نصف برامجنا بصدق ولا ندّعي اعتمادًا خارجيًا لا نملكه.",
            },
          ],
        },
      ]}
    />
  );
}

export default async function AboutPage() {
  const page = await loadPage();
  if (!page) return <AboutFallback />;

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
