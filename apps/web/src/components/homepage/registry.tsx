import type { ComponentType } from "react";
import type {
  FaqContent,
  FeaturesContent,
  HeroContent,
  HomepageBlockType,
  HomepageSection,
  PartnersContent,
  TestimonialsContent,
} from "@/lib/homepage/api";

// Original block components (unchanged) — adapted to the { section } registry signature.
import { Hero } from "@/components/landing/hero";
import { TrustedBy } from "@/components/landing/trusted-by";
import { FeaturesSection } from "@/components/homepage/features-section";
import { TestimonialsSection } from "@/components/homepage/testimonials-section";
import { FaqSection } from "@/components/homepage/faq-section";

// Expansion block components.
import { StatisticsBlock } from "@/components/homepage/blocks/statistics-block";
import { NumbersBlock } from "@/components/homepage/blocks/numbers-block";
import { CategoriesBlock } from "@/components/homepage/blocks/categories-block";
import { FeaturedCoursesBlock } from "@/components/homepage/blocks/featured-courses-block";
import { FeaturedEventsBlock } from "@/components/homepage/blocks/featured-events-block";
import { ClientsBlock } from "@/components/homepage/blocks/clients-block";
import { PricingPreviewBlock } from "@/components/homepage/blocks/pricing-preview-block";
import { CtaBlock } from "@/components/homepage/blocks/cta-block";
import { VideoBlock } from "@/components/homepage/blocks/video-block";
import { GalleryBlock } from "@/components/homepage/blocks/gallery-block";
import { TimelineBlock } from "@/components/homepage/blocks/timeline-block";
import { TeamBlock } from "@/components/homepage/blocks/team-block";
import { NewsletterBlock } from "@/components/homepage/blocks/newsletter-block";
import { ContactStripBlock } from "@/components/homepage/blocks/contact-strip-block";
import { RichTextBlock } from "@/components/homepage/blocks/rich-text-block";
import { ComparisonTableBlock } from "@/components/homepage/blocks/comparison-table-block";
import { BrandSectionBlock } from "@/components/homepage/blocks/brand-section-block";

export type BlockProps = { section: HomepageSection };

// ----- Thin adapters for the original blocks (they take a typed `content` prop) -----

function HeroBlock({ section }: BlockProps) {
  return <Hero content={section.content as HeroContent} />;
}
function FeaturesBlock({ section }: BlockProps) {
  return <FeaturesSection content={section.content as FeaturesContent} />;
}
function PartnersBlock({ section }: BlockProps) {
  return <TrustedBy content={section.content as PartnersContent} />;
}
function TestimonialsBlock({ section }: BlockProps) {
  return <TestimonialsSection content={section.content as TestimonialsContent} />;
}
function FaqBlock({ section }: BlockProps) {
  return <FaqSection content={section.content as FaqContent} />;
}

/**
 * The single source of truth mapping every homepage block type to its renderer. The homepage maps
 * the API's ordered sections through this registry — there is no hardcoded per-section switch.
 * `footer` is rendered by the page chrome (LandingFooter) and `seo` is metadata-only, so neither is
 * a body renderer here. An unknown/unsupported type resolves to undefined and renders nothing.
 */
export const blockRegistry: Partial<Record<HomepageBlockType, ComponentType<BlockProps>>> = {
  hero: HeroBlock,
  features: FeaturesBlock,
  partners: PartnersBlock,
  testimonials: TestimonialsBlock,
  faq: FaqBlock,
  statistics: StatisticsBlock,
  numbers: NumbersBlock,
  categories: CategoriesBlock,
  featured_courses: FeaturedCoursesBlock,
  featured_events: FeaturedEventsBlock,
  clients: ClientsBlock,
  logo_cloud: ClientsBlock,
  pricing_preview: PricingPreviewBlock,
  cta: CtaBlock,
  video: VideoBlock,
  gallery: GalleryBlock,
  timeline: TimelineBlock,
  team: TeamBlock,
  newsletter: NewsletterBlock,
  contact_strip: ContactStripBlock,
  rich_text: RichTextBlock,
  comparison_table: ComparisonTableBlock,
  brand_section: BrandSectionBlock,
};

/** Resolve the renderer for a section, or null when the block type is unknown/unsupported. */
export function BlockRenderer({ section }: BlockProps) {
  const Component = blockRegistry[section.type];
  return Component ? <Component section={section} /> : null;
}
