import { api } from "@/lib/api/client";
import { proxyMediaUrl } from "@/lib/media/proxy";
import type { Paginated } from "@/types/api";

/**
 * Public Blog CMS client. Blog articles are managed in the admin CMS and served published-only via
 * GET /api/v1/blog*. This module fetches the paginated post list, a single post by slug, and the
 * category list, and ALWAYS fails safe: it returns [] / null on any error or 404 so the blog pages
 * degrade gracefully (empty state / notFound) instead of throwing. All content is bilingual.
 *
 * Cover image URLs are resolved server-side to a public URL by the API, then wrapped with
 * proxyMediaUrl so they load same-origin in dev (exactly like course cards).
 */

export type Localized = { en: string; ar: string };

export type BlogCategory = {
  id: string;
  slug: string;
  name: Localized;
  description: Localized | null;
  position: number;
};

/** A post's category ref as embedded in list/detail payloads. */
export type BlogPostCategoryRef = { slug: string; name: Localized };

export type BlogPostSeo = {
  meta_title?: Localized | null;
  meta_description?: Localized | null;
  keywords?: string | null;
  canonical?: string | null;
  robots_index?: boolean;
  robots_follow?: boolean;
  og_title?: Localized | string | null;
  og_description?: Localized | string | null;
  og_image?: string | null;
  twitter_card?: string | null;
  json_ld?: string | null;
};

/** Summary payload (GET /api/v1/blog/posts). */
export type BlogPostSummary = {
  id: string;
  slug: string;
  title: Localized;
  excerpt: Localized | null;
  cover_image: string | null;
  category: BlogPostCategoryRef | null;
  author: string | null;
  is_featured: boolean;
  reading_minutes: number | null;
  published_at: string | null;
};

/** Full post payload (GET /api/v1/blog/posts/{slug}). */
export type BlogPost = BlogPostSummary & {
  body: Localized;
  updated_at: string | null;
  seo: BlogPostSeo;
};

export type BlogFilters = {
  category?: string;
  featured?: boolean;
  page?: number;
  perPage?: number;
};

function toQuery(filters: BlogFilters): string {
  const p = new URLSearchParams();
  if (filters.category) p.set("category", filters.category);
  if (filters.featured) p.set("featured", "true");
  if (filters.page) p.set("page", String(filters.page));
  if (filters.perPage) p.set("per_page", String(filters.perPage));
  const s = p.toString();
  return s ? `?${s}` : "";
}

/** Narrow an unknown payload to a BlogPost: a plain object carrying a non-empty string `slug`. */
function isBlogPost(data: unknown): data is BlogPost {
  return (
    typeof data === "object" &&
    data !== null &&
    !Array.isArray(data) &&
    typeof (data as { slug?: unknown }).slug === "string" &&
    (data as { slug: string }).slug.length > 0
  );
}

/** Proxy a summary/post's cover_image for same-origin loading in dev (no-op in prod). */
function withProxiedCover<T extends { cover_image: string | null }>(post: T): T {
  return { ...post, cover_image: proxyMediaUrl(post.cover_image) ?? null };
}

/**
 * Fetch a page of published posts. Returns an empty paginated shape on any failure so the list page
 * can render its empty state instead of throwing. Optional filters: category slug, featured-only.
 */
export async function getBlogPosts(filters: BlogFilters = {}): Promise<Paginated<BlogPostSummary>> {
  try {
    const payload = await api.get<Paginated<BlogPostSummary>>(`blog/posts${toQuery(filters)}`, {
      auth: false,
      cache: "no-store",
    });
    const data = Array.isArray(payload?.data) ? payload.data.map(withProxiedCover) : [];
    return { ...payload, data };
  } catch {
    return {
      data: [],
      meta: { current_page: 1, per_page: filters.perPage ?? 9, total: 0, last_page: 1 },
      links: { first: null, last: null, prev: null, next: null },
    };
  }
}

/**
 * Fetch a single published post by slug. Returns null when the post is not there or not live, so
 * the caller can 404 via notFound(). `preview` requests the admin draft (best-effort; needs an
 * authenticated admin session).
 *
 * A transport failure RETHROWS rather than returning null. The caller turns null into a 404, and a
 * 404 is a claim that the post does not exist — a claim a crawler acts on by dropping the URL from
 * the index. An API blip must surface as an error page it will retry, not as a permanent verdict.
 */
export async function getBlogPost(slug: string, preview = false): Promise<BlogPost | null> {
  try {
    const path = preview ? `blog/posts/${slug}/preview` : `blog/posts/${slug}`;
    const data = await api.data<BlogPost | null>(path, { auth: preview, cache: "no-store" });
    return isBlogPost(data) ? withProxiedCover(data) : null;
  } catch (error) {
    if ((error as { status?: number } | null)?.status === 404) return null;
    throw error;
  }
}

/** Fetch the list of blog categories (ordered). Returns [] on any failure. */
export async function getBlogCategories(): Promise<BlogCategory[]> {
  try {
    const payload = await api.data<{ categories?: BlogCategory[] } | null>("blog/categories", {
      auth: false,
    });
    const categories = payload && Array.isArray(payload.categories) ? payload.categories : [];
    return categories.filter(
      (c): c is BlogCategory => Boolean(c) && typeof c.slug === "string" && c.slug.length > 0,
    );
  } catch {
    return [];
  }
}

/** Pick a localized value from either a Localized bag or a plain string (SEO og_* fields). */
export function pickSeoText(
  value: Localized | string | null | undefined,
  locale: "en" | "ar",
): string | undefined {
  if (value == null) return undefined;
  if (typeof value === "string") return value || undefined;
  return value[locale] || value.en || undefined;
}
