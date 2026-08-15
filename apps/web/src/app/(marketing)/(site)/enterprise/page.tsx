import type { Metadata } from "next";
import { siteConfig } from "@/config/site";
import { jsonLdScript } from "@/lib/seo/json-ld";
import { EnterprisePage } from "@/components/marketing/enterprise-page";
import { ENTERPRISE_FAQ } from "@/components/marketing/enterprise-faq";

const TITLE = "Enterprise learning for organizations — HElbaron";
const DESCRIPTION =
  "Administer learners at scale, deliver courses and live cohorts, and prove completion with verifiable certificates — Arabic-first, for companies, academies, and public-sector programs.";

export const metadata: Metadata = {
  title: TITLE,
  description: DESCRIPTION,
  alternates: { canonical: "/enterprise" },
  openGraph: { title: TITLE, description: DESCRIPTION, url: "/enterprise", type: "website" },
  twitter: { card: "summary_large_image", title: TITLE, description: DESCRIPTION },
};

export default function Page() {
  const breadcrumb = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: "Home", item: `${siteConfig.url}/` },
      { "@type": "ListItem", position: 2, name: "Enterprise", item: `${siteConfig.url}/enterprise` },
    ],
  };
  const faq = {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    mainEntity: ENTERPRISE_FAQ.map((f) => ({
      "@type": "Question",
      name: f.q.en,
      acceptedAnswer: { "@type": "Answer", text: f.a.en },
    })),
  };
  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLdScript(breadcrumb) }} />
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLdScript(faq) }} />
      <EnterprisePage />
    </>
  );
}
