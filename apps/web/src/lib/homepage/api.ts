import { api } from "@/lib/api/client";

/**
 * Public Homepage CMS client. The marketing homepage is driven by predefined, typed blocks managed
 * in the admin builder; this module fetches the published content (or the admin draft in preview
 * mode) and exposes strongly-typed block shapes. All content is bilingual ({ en, ar }).
 *
 * Beyond the original seven blocks the CMS now serves seventeen presentational block types plus
 * per-block presentation metadata (layout / spacing / alignment / container / animation / theme /
 * background), an accessibility label, device-visibility flags, and — for entity-backed blocks —
 * a server-resolved `resolved` payload (featured courses / events / categories).
 */

export type Localized = { en: string; ar: string };
export type LocalizedLink = { label: Localized; href: string };

export type HomepageBlockType =
  | "hero"
  | "features"
  | "testimonials"
  | "partners"
  | "faq"
  | "footer"
  | "seo"
  | "statistics"
  | "numbers"
  | "categories"
  | "featured_courses"
  | "featured_events"
  | "clients"
  | "pricing_preview"
  | "cta"
  | "video"
  | "gallery"
  | "timeline"
  | "team"
  | "newsletter"
  | "contact_strip"
  | "rich_text"
  | "logo_cloud"
  | "comparison_table"
  | "brand_section";

// ----- Original block content shapes -----

export type HeroContent = {
  headline?: Localized;
  subheadline?: Localized;
  cta_primary?: LocalizedLink;
  cta_secondary?: LocalizedLink;
  image?: string | null;
};

export type FeatureItem = { title?: Localized; description?: Localized; icon?: string | null };
export type FeaturesContent = { items?: FeatureItem[] };

export type TestimonialItem = { quote?: Localized; author?: string; role?: Localized; avatar?: string | null };
export type TestimonialsContent = { items?: TestimonialItem[] };

export type PartnerItem = { name?: string; logo?: string | null; href?: string | null };
export type PartnersContent = { items?: PartnerItem[] };

export type FaqItem = { question?: Localized; answer?: Localized };
export type FaqContent = { items?: FaqItem[] };

export type FooterColumn = { title?: Localized; links?: LocalizedLink[] };
export type FooterContent = { tagline?: Localized; columns?: FooterColumn[] };

export type SeoContent = {
  meta_title?: Localized;
  meta_description?: Localized;
  og_image?: string | null;
  canonical?: string | null;
};

// ----- Expansion block content shapes -----

export type StatItem = { value?: string; suffix?: string; label?: Localized };
export type StatisticsContent = { heading?: Localized; items?: StatItem[] };

export type NumberItem = { value?: string; label?: Localized };
export type NumbersContent = { heading?: Localized; items?: NumberItem[] };

export type CategoriesContent = { heading?: Localized; subheading?: Localized };
export type FeaturedCoursesContent = { heading?: Localized; subheading?: Localized; cta?: LocalizedLink };
export type FeaturedEventsContent = { heading?: Localized; subheading?: Localized; cta?: LocalizedLink };

export type ClientsContent = { heading?: Localized; items?: PartnerItem[] };
export type LogoCloudContent = { heading?: Localized; items?: PartnerItem[] };

export type PricingPlan = {
  name?: Localized;
  price?: string;
  period?: Localized;
  highlighted?: boolean;
  features?: Localized[];
  cta?: LocalizedLink;
};
export type PricingPreviewContent = { heading?: Localized; subheading?: Localized; plans?: PricingPlan[] };

export type CtaContent = {
  headline?: Localized;
  subheadline?: Localized;
  cta_primary?: LocalizedLink;
  cta_secondary?: LocalizedLink;
};

export type VideoContent = { heading?: Localized; url?: string | null; poster?: string | null; caption?: Localized };

export type GalleryItem = { image?: string | null; caption?: Localized };
export type GalleryContent = { heading?: Localized; items?: GalleryItem[] };

export type TimelineItem = { date?: Localized; title?: Localized; description?: Localized };
export type TimelineContent = { heading?: Localized; items?: TimelineItem[] };

export type TeamMember = { name?: string; role?: Localized; avatar?: string | null; href?: string | null };
export type TeamContent = { heading?: Localized; items?: TeamMember[] };

export type NewsletterContent = {
  heading?: Localized;
  subheading?: Localized;
  placeholder?: Localized;
  cta?: Localized;
  action_url?: string | null;
};

export type ContactStripContent = {
  heading?: Localized;
  subheading?: Localized;
  phone?: string;
  email?: string;
  address?: Localized;
  cta?: LocalizedLink;
};

export type RichTextContent = { title?: Localized; body?: Localized };

export type ComparisonRow = { cells?: Localized[] };
export type ComparisonTableContent = { heading?: Localized; columns?: Localized[]; rows?: ComparisonRow[] };

// ----- Presentation + resolved entities -----

export type BlockBackground = {
  color?: string | null;
  image?: string | null;
  video?: string | null;
  overlay?: string | null;
};

export type BlockPresentation = {
  layout_variant?: string | null;
  spacing?: string | null;
  alignment?: string | null;
  container_width?: string | null;
  animation?: string | null;
  theme_variant?: string | null;
  background?: BlockBackground | null;
};

export type BlockVisibility = { desktop: boolean; tablet: boolean; mobile: boolean };

export type ResolvedCourse = { id: string; title?: Localized; subtitle?: Localized; slug: string; thumbnail?: string | null; level?: string | null; href: string };
export type ResolvedEvent = { id: string; title?: Localized; description?: Localized; starts_at?: string | null; href: string };
export type ResolvedCategory = { id: string; name?: Localized; description?: Localized; slug: string; href: string };
export type ResolvedEntities = {
  courses?: ResolvedCourse[];
  events?: ResolvedEvent[];
  categories?: ResolvedCategory[];
};

export type HomepageSection = {
  key: string;
  type: HomepageBlockType;
  position: number;
  content: Record<string, unknown>;
  resolved?: ResolvedEntities | null;
  presentation?: BlockPresentation | null;
  accessibility_label?: Localized | null;
  visibility?: BlockVisibility | null;
};

export type HomepagePayload = {
  sections: HomepageSection[];
  seo: SeoContent | null;
};

/**
 * Fetch the homepage content server-side. Returns null on any failure so the page can fall back to
 * its built-in default content (the homepage is never empty). `preview` requests the admin draft.
 */
export async function getHomepage(preview = false): Promise<HomepagePayload | null> {
  try {
    const path = preview ? "homepage/preview" : "homepage";
    return await api.data<HomepagePayload>(path, { auth: false, cache: "no-store" });
  } catch {
    return null;
  }
}

/** Index sections by block type for O(1) lookup while rendering. */
export function indexSections(sections: HomepageSection[] | undefined): Map<HomepageBlockType, HomepageSection> {
  const map = new Map<HomepageBlockType, HomepageSection>();
  for (const s of sections ?? []) {
    if (!map.has(s.type)) map.set(s.type, s);
  }
  return map;
}

/** Enabled content blocks (excludes the SEO block) in render order. */
export function orderedBlocks(payload: HomepagePayload | null): HomepageSection[] {
  return (payload?.sections ?? [])
    .filter((s) => s.type !== "seo")
    .slice()
    .sort((a, b) => a.position - b.position);
}
