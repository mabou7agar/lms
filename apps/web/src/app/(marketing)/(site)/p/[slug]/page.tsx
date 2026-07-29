import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { cache } from "react";
import { getStaticPage, type StaticPage } from "@/lib/pages/api";
import { buildPageMetadata, pageJsonLd } from "@/lib/pages/metadata";
import { CmsPage } from "@/components/marketing/cms-page";
import { jsonLdScript } from "@/lib/seo/json-ld";

/** Deduped server-side fetch shared by generateMetadata and the page render. */
const loadPage = cache(async (slug: string): Promise<StaticPage | null> => getStaticPage(slug));

type Params = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { slug } = await params;
  const page = await loadPage(slug);
  if (!page) return { title: "Page not found" };
  return buildPageMetadata(page, `/p/${page.slug}`);
}

/**
 * Generic renderer for any published CMS page that does not have its own bespoke route
 * (cookies / refund-policy / faq / careers / help + custom pages). 404s via notFound() when the
 * page is not live. Body HTML is server-sanitized and re-sanitized in CmsPage before injection.
 */
export default async function CustomStaticPage({ params }: Params) {
  const { slug } = await params;
  const page = await loadPage(slug);
  if (!page) notFound();

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
