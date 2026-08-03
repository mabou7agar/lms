import type { Metadata } from "next";
import { cookies } from "next/headers";
import { brandTheme } from "@/config/theme";
import { defaultLocale, isLocale, localeCookieName, type Locale } from "@/lib/i18n/config";
import {
  getHomepage,
  indexSections,
  orderedBlocks,
  type FooterContent,
  type Localized,
} from "@/lib/homepage/api";
import { AnnouncementBar } from "@/components/landing/announcement-bar";
import { LandingHeader } from "@/components/landing/landing-header";
import { Hero } from "@/components/landing/hero";
import { TrustedBy } from "@/components/landing/trusted-by";
import { ProductModes } from "@/components/landing/product-modes";
import { FinalCta } from "@/components/landing/final-cta";
import {
  ProofBand,
  WhyHelbaron,
  LearningExperience,
  LearningJourney,
  Testimonials,
  Instructors,
  EnterpriseTrust,
} from "@/components/landing/home-sections";
import { FeaturedCourses } from "@/components/marketing/featured-courses";
import { LandingFooter } from "@/components/landing/landing-footer";
import { BlockRenderer } from "@/components/homepage/registry";
import { PersonaPaths } from "@/components/landing/persona-paths";
import { HomeAnalytics } from "@/components/landing/home-analytics";

// The homepage is content-driven (CMS) and locale-aware (cookie), so it is rendered per-request.
export const dynamic = "force-dynamic";

async function readLocale(): Promise<Locale> {
  const store = await cookies();
  const value = store.get(localeCookieName)?.value;
  return isLocale(value) ? value : defaultLocale;
}

function pick(loc: Localized | undefined, locale: Locale): string {
  return loc ? (loc[locale] ?? loc.en) : "";
}

export async function generateMetadata(): Promise<Metadata> {
  const [locale, data] = await Promise.all([readLocale(), getHomepage()]);
  const seo = data?.seo ?? null;

  const title = seo?.meta_title ? pick(seo.meta_title, locale) : `${brandTheme.name} — ${brandTheme.tagline.en}`;
  const description = seo?.meta_description ? pick(seo.meta_description, locale) : brandTheme.footer.description.en;
  const canonical = seo?.canonical || "/";
  const ogImage = seo?.og_image || undefined;

  return {
    title,
    description,
    alternates: { canonical },
    openGraph: {
      title,
      description,
      url: canonical,
      ...(ogImage ? { images: [{ url: ogImage }] } : {}),
    },
  };
}

export default async function LandingPage({
  searchParams,
}: {
  searchParams: Promise<{ preview?: string }>;
}) {
  const sp = await searchParams;
  const preview = sp?.preview === "1";

  const data = await getHomepage(preview);
  const blocks = orderedBlocks(data);
  const byType = indexSections(data?.sections);

  const bodyBlocks = blocks.filter((b) => b.type !== "footer");
  const footer = byType.get("footer");
  const footerContent = footer ? (footer.content as FooterContent) : undefined;

  // Never render an empty homepage: if the API is unreachable, fall back to the built-in brand
  // sections (the block components default to brand content when no CMS content is supplied).
  // Otherwise every ordered block is rendered dynamically through the block registry — no hardcoded
  // per-section switch. Unknown/unsupported block types render nothing (BlockRenderer returns null).
  const body =
    bodyBlocks.length > 0 ? (
      <>
        {bodyBlocks.map((section) => <BlockRenderer key={section.key} section={section} />)}
        <FeaturedCourses />
      </>
    ) : (
      // Built-in premium brand homepage (rendered when the CMS has no published blocks).
      <>
        <Hero />
        <ProofBand />
        <TrustedBy />
        <ProductModes />
        <WhyHelbaron />
        <LearningExperience />
        <LearningJourney />
        <FeaturedCourses />
        <Testimonials />
        <Instructors />
        <EnterpriseTrust />
        <FinalCta />
      </>
    );

  return (
    <>
      <AnnouncementBar />
      <LandingHeader />
      <main id="main-content" className="flex-1">
        {body}
        {/* Conversion band: routes visitors to persona journeys, comparison, and pricing. */}
        <PersonaPaths />
        <HomeAnalytics />
      </main>
      <LandingFooter content={footerContent} />
    </>
  );
}
