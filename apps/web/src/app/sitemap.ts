import type { MetadataRoute } from "next";
import { listPublishedPages } from "@/lib/pages/api";
import { getSeoSitemap } from "@/lib/seo/api";

const baseUrl = (process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000").replace(/\/+$/, "");

/** Normalize a managed entry URL (absolute or site-relative) into an absolute site URL. */
function toAbsolute(url: string): string {
  if (/^https?:\/\//i.test(url)) return url;
  return url === "/" ? `${baseUrl}/` : `${baseUrl}${url.startsWith("/") ? url : `/${url}`}`;
}

const CHANGEFREQS = new Set([
  "always", "hourly", "daily", "weekly", "monthly", "yearly", "never",
]);

/** Static public marketing/catalog routes. Detail pages are discovered via crawling. */
const publicRoutes = [
  "/",
  "/courses",
  "/categories",
  "/cohorts",
  "/workshops",
  "/events",
  "/enterprise",
  "/advisory",
  "/solutions",
  "/trainers",
  "/products",
  "/pricing",
  "/compare",
  "/about",
  "/contact",
  "/verify",
  "/privacy",
  "/terms",
  "/login",
  "/register",
];

/** Slugs that already have their own preserved top-level route (don't emit a /p/ duplicate). */
const OWN_ROUTE_SLUGS = new Set(["about", "contact", "privacy", "terms"]);

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const lastModified = new Date();

  const staticEntries: MetadataRoute.Sitemap = publicRoutes.map((route) => ({
    url: route === "/" ? `${baseUrl}/` : `${baseUrl}${route}`,
    lastModified,
    changeFrequency: "weekly" as const,
    priority: route === "/" ? 1 : 0.7,
  }));

  // Published CMS pages — served at /p/{slug} unless they already have a preserved route. Fails
  // safe: listPublishedPages() returns [] when the API is unreachable, keeping the static list.
  const cmsPages = await listPublishedPages();
  const cmsEntries: MetadataRoute.Sitemap = cmsPages
    .filter((p) => !OWN_ROUTE_SLUGS.has(p.slug))
    .map((p) => ({
      url: `${baseUrl}/p/${p.slug}`,
      lastModified: p.updated_at ? new Date(p.updated_at) : lastModified,
      changeFrequency: "weekly" as const,
      priority: 0.6,
    }));

  // Managed SEO entities (SEO Manager) with per-entity priority/changefreq. Deduplicated against the
  // static + CMS URLs so no duplicate <url> is ever emitted. Fails safe: getSeoSitemap() returns []
  // when the API is unreachable, leaving the static + CMS lists intact.
  const seen = new Set([...staticEntries, ...cmsEntries].map((e) => e.url));
  const managed = await getSeoSitemap();
  const seoEntries: MetadataRoute.Sitemap = [];
  for (const entry of managed) {
    const url = toAbsolute(entry.url);
    if (seen.has(url)) continue;
    seen.add(url);
    seoEntries.push({
      url,
      lastModified: entry.updated_at ? new Date(entry.updated_at) : lastModified,
      changeFrequency: entry.changefreq && CHANGEFREQS.has(entry.changefreq)
        ? (entry.changefreq as MetadataRoute.Sitemap[number]["changeFrequency"])
        : ("weekly" as const),
      priority: typeof entry.priority === "number" ? entry.priority : 0.6,
    });
  }

  return [...staticEntries, ...cmsEntries, ...seoEntries];
}
