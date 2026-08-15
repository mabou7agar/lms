import type { HomepageSection } from "@/lib/homepage/api";

// The bespoke brand-default home components. Each renders its own on-brand content and needs no CMS
// content prop — the `brand_section` block is purely presentational: it selects WHICH built-in
// section to render (by `content.key`) so the homepage can be reordered/toggled from the admin
// builder without changing how any section looks. Hero and TrustedBy accept an optional `content`
// prop; we deliberately omit it so they fall back to their brand defaults.
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

/** The set of built-in home sections a `brand_section` block may select. */
export type BrandSectionKey =
  | "hero"
  | "proof_band"
  | "trusted_by"
  | "product_modes"
  | "why_helbaron"
  | "learning_experience"
  | "learning_journey"
  | "featured_courses"
  | "testimonials"
  | "instructors"
  | "enterprise_trust"
  | "final_cta";

/**
 * Render the built-in brand section named by `section.content.key`. An unknown/missing key renders
 * nothing (null), matching the registry's "unknown block type renders nothing" contract.
 */
export function BrandSectionBlock({ section }: { section: HomepageSection }) {
  const key = typeof section.content?.key === "string" ? section.content.key : "";

  switch (key as BrandSectionKey) {
    case "hero":
      return <Hero />;
    case "proof_band":
      return <ProofBand />;
    case "trusted_by":
      return <TrustedBy />;
    case "product_modes":
      return <ProductModes />;
    case "why_helbaron":
      return <WhyHelbaron />;
    case "learning_experience":
      return <LearningExperience />;
    case "learning_journey":
      return <LearningJourney />;
    case "featured_courses":
      return <FeaturedCourses />;
    case "testimonials":
      return <Testimonials />;
    case "instructors":
      return <Instructors />;
    case "enterprise_trust":
      return <EnterpriseTrust />;
    case "final_cta":
      return <FinalCta />;
    default:
      return null;
  }
}
