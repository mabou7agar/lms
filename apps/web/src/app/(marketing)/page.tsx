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
  EnterpriseTrust,
} from "@/components/landing/home-sections";
import { FeaturedCourses } from "@/components/marketing/featured-courses";
import { FeaturedBundles } from "@/components/marketing/featured-bundles";
import { FeaturedTrainers } from "@/components/marketing/featured-trainers";
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
      type: "website",
      ...(ogImage ? { images: [{ url: ogImage }] } : {}),
    },
    twitter: {
      card: "summary_large_image",
      title,
      description,
      ...(ogImage ? { images: [ogImage] } : {}),
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

  // Single source of truth for the course grid: when the CMS publishes a featured-courses block with
  // real (server-resolved) courses, it OWNS that surface. The static demo grid is a fallback only — it
  // renders when there is no CMS course data, and is never shown alongside the real block.
  const hasCmsFeaturedCourses = bodyBlocks.some(
    (b) =>
      (b.type === "featured_courses" && (b.resolved?.courses?.length ?? 0) > 0) ||
      // A brand_section pointing at the built-in featured-courses component already renders the grid,
      // so the trailing fallback <FeaturedCourses /> must be suppressed to avoid a duplicate section.
      (b.type === "brand_section" && b.content?.key === "featured_courses"),
  );
  const hasCmsTrainers = bodyBlocks.some(
    (b) =>
      b.type === "team" ||
      (b.type === "brand_section" && b.content?.key === "instructors"),
  );

  const socialProofIndex = bodyBlocks.findIndex((section) => section.type === "testimonials" || section.type === "faq");
  const salesInsertionIndex = socialProofIndex === -1 ? bodyBlocks.length : socialProofIndex;
  const cmsCourseSurfaceIndex = bodyBlocks.findIndex(
    (section) =>
      section.type === "featured_courses" ||
      (section.type === "brand_section" && section.content?.key === "featured_courses"),
  );
  const trainerInsertionIndex = hasCmsFeaturedCourses && cmsCourseSurfaceIndex !== -1
    ? cmsCourseSurfaceIndex + 1
    : salesInsertionIndex;

  const dynamicSalesSections = bodyBlocks.flatMap((section) => {
    const index = bodyBlocks.indexOf(section);

    // Put the sales proof high on the page: after the service/features story and before testimonials
    // or FAQ. The prior fallback rendered courses at the very end, which made the homepage feel like
    // it had no catalogue.
    return [
      ...(!hasCmsFeaturedCourses && index === salesInsertionIndex
        ? [<FeaturedCourses key="home-real-courses" />, <FeaturedBundles key="home-real-bundles" />]
        : []),
      ...(!hasCmsTrainers && index === trainerInsertionIndex ? [<FeaturedTrainers key="home-real-trainers" />] : []),
      <BlockRenderer key={section.key} section={section} />,
    ];
  });

  // Never render an empty homepage: if the API is unreachable, fall back to the built-in brand
  // sections (the block components default to brand content when no CMS content is supplied).
  // Otherwise every ordered block is rendered dynamically through the block registry — no hardcoded
  // per-section switch. Unknown/unsupported block types render nothing (BlockRenderer returns null).
  const body =
    bodyBlocks.length > 0 ? (
      <>
        {dynamicSalesSections}
        {!hasCmsFeaturedCourses && salesInsertionIndex === bodyBlocks.length ? (
          <>
            <FeaturedCourses />
            <FeaturedBundles />
          </>
        ) : null}
        {!hasCmsTrainers && trainerInsertionIndex === bodyBlocks.length ? <FeaturedTrainers /> : null}
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
        {/* Bundles sit with the courses, not after the FAQ: they are the larger purchase and the
            one a visitor is least likely to go looking for on their own. */}
        <FeaturedBundles />
        <FeaturedTrainers />
        <Testimonials />
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
