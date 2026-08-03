import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { siteConfig } from "@/config/site";
import { jsonLdScript } from "@/lib/seo/json-ld";
import { personaSlug, personaFromSlug } from "@/config/personas-content";
import { PersonaPage } from "@/components/marketing/persona-page";

type Params = { params: Promise<{ persona: string }> };

export function generateStaticParams(): { persona: string }[] {
  return Object.values(personaSlug).map((persona) => ({ persona }));
}

const META: Record<string, { title: string; description: string }> = {
  enterprise: {
    title: "HElbaron for Companies & Enterprise L&D",
    description: "Launch role-based, Arabic-first learning programs, administer learners at scale, and report on completion and outcomes.",
  },
  academies: {
    title: "HElbaron for Training Academies & Centers",
    description: "Publish a branded catalog, sell courses and memberships, and run live cohorts end to end with verifiable certificates.",
  },
  instructors: {
    title: "HElbaron for Independent Instructors & Experts",
    description: "Author courses in the studio, publish to a bilingual audience, assess learners, and issue verifiable certificates.",
  },
  government: {
    title: "HElbaron for Government & Public-Sector Programs",
    description: "Run cohort-based public training programs in Arabic with administration, reporting, and verifiable certificates.",
  },
};

export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { persona } = await params;
  const meta = META[persona];
  if (!meta) return { title: "Solutions — HElbaron" };
  const url = `/solutions/${persona}`;
  return {
    title: meta.title,
    description: meta.description,
    alternates: { canonical: url },
    openGraph: { title: meta.title, description: meta.description, url, type: "website" },
    twitter: { card: "summary_large_image", title: meta.title, description: meta.description },
  };
}

export default async function SolutionPersonaPage({ params }: Params) {
  const { persona } = await params;
  const resolved = personaFromSlug(persona);
  if (!resolved) notFound();

  const meta = META[persona];
  const breadcrumb = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: "Home", item: `${siteConfig.url}/` },
      { "@type": "ListItem", position: 2, name: "Solutions", item: `${siteConfig.url}/solutions` },
      { "@type": "ListItem", position: 3, name: meta.title, item: `${siteConfig.url}/solutions/${persona}` },
    ],
  };

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLdScript(breadcrumb) }} />
      <PersonaPage id={resolved.id} />
    </>
  );
}
