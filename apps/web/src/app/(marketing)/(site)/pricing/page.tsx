import type { Metadata } from "next";
import { siteConfig } from "@/config/site";
import { jsonLdScript } from "@/lib/seo/json-ld";
import { PricingPage } from "@/components/marketing/pricing-page";
import { PRICING_FAQ } from "@/components/marketing/pricing-faq";

const TITLE = "Pricing — HElbaron";
const DESCRIPTION =
  "Pay for what you use: per-course pricing shown on each course, live cohorts and workshops priced per program, and custom enterprise agreements. No invented tiers or numbers.";

export const metadata: Metadata = {
  title: TITLE,
  description: DESCRIPTION,
  alternates: { canonical: "/pricing" },
  openGraph: { title: TITLE, description: DESCRIPTION, url: "/pricing", type: "website" },
  twitter: { card: "summary_large_image", title: TITLE, description: DESCRIPTION },
};

export default function Page() {
  const breadcrumb = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: "Home", item: `${siteConfig.url}/` },
      { "@type": "ListItem", position: 2, name: "Pricing", item: `${siteConfig.url}/pricing` },
    ],
  };
  const faq = {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    mainEntity: PRICING_FAQ.map((f) => ({
      "@type": "Question",
      name: f.q.en,
      acceptedAnswer: { "@type": "Answer", text: f.a.en },
    })),
  };
  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLdScript(breadcrumb) }} />
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLdScript(faq) }} />
      <PricingPage />
    </>
  );
}
