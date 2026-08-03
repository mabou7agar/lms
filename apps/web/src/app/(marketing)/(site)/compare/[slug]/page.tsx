import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { siteConfig } from "@/config/site";
import { jsonLdScript } from "@/lib/seo/json-ld";
import { competitorSlugs, getCompetitor } from "@/config/comparison";
import { ComparisonDetail } from "@/components/marketing/comparison-page";

type Params = { params: Promise<{ slug: string }> };

export function generateStaticParams(): { slug: string }[] {
  return competitorSlugs.map((slug) => ({ slug }));
}

export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { slug } = await params;
  const c = getCompetitor(slug);
  if (!c) return { title: "Compare HElbaron" };

  const title = `HElbaron vs ${c.name}`;
  const description = `A factual, category-level comparison of HElbaron and ${c.name} — capabilities and operating models, with honest "best for" guidance.`;
  const url = `/compare/${c.slug}`;
  return {
    title,
    description,
    alternates: { canonical: url },
    openGraph: { title, description, url, type: "website" },
    twitter: { card: "summary_large_image", title, description },
  };
}

export default async function CompareSlugPage({ params }: Params) {
  const { slug } = await params;
  const c = getCompetitor(slug);
  if (!c) notFound();

  const breadcrumb = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: "Home", item: `${siteConfig.url}/` },
      { "@type": "ListItem", position: 2, name: "Compare", item: `${siteConfig.url}/compare` },
      { "@type": "ListItem", position: 3, name: `HElbaron vs ${c.name}`, item: `${siteConfig.url}/compare/${c.slug}` },
    ],
  };

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLdScript(breadcrumb) }} />
      <ComparisonDetail slug={c.slug} />
    </>
  );
}
