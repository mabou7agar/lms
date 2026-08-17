import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { cache } from "react";
import { getBlogPost, pickSeoText, type BlogPost } from "@/lib/blog/api";
import { notFoundMetadata } from "@/lib/seo/not-found";
import { BlogPostView } from "@/components/marketing/blog/blog-post-view";

/** Deduped server-side fetch shared by generateMetadata and the page render. */
const loadPost = cache(async (slug: string): Promise<BlogPost | null> => getBlogPost(slug));

type Params = { params: Promise<{ slug: string }> };

/**
 * Build Next.js Metadata from the post's resolved SEO block (emitted in English, the canonical
 * crawl locale, mirroring the CMS pages). Falls back to a generic title when the post is missing.
 */
export async function generateMetadata({ params }: Params): Promise<Metadata> {
  const { slug } = await params;
  const post = await loadPost(slug);
  // Marked noindex rather than 404'd — see notFoundMetadata. The body still 404s for the reader.
  if (!post) return notFoundMetadata("Post not found");

  const seo = post.seo ?? {};
  const title = pickSeoText(seo.meta_title, "en") ?? post.title.en;
  const description = pickSeoText(seo.meta_description, "en") ?? post.excerpt?.en ?? undefined;
  const canonical = seo.canonical || `/blog/${post.slug}`;
  const ogTitle = pickSeoText(seo.og_title, "en") ?? title;
  const ogDescription = pickSeoText(seo.og_description, "en") ?? description;
  const ogImage = seo.og_image ?? post.cover_image ?? undefined;

  return {
    title,
    description,
    alternates: { canonical },
    openGraph: {
      title: ogTitle,
      description: ogDescription,
      url: canonical,
      type: "article",
      ...(ogImage ? { images: [{ url: ogImage }] } : {}),
    },
    twitter: {
      card: (seo.twitter_card as "summary" | "summary_large_image" | undefined) ?? "summary_large_image",
      title: ogTitle,
      description: ogDescription,
      ...(ogImage ? { images: [ogImage] } : {}),
    },
    robots: {
      index: seo.robots_index ?? true,
      follow: seo.robots_follow ?? true,
    },
  };
}

/**
 * Public single-post page. 404s via notFound() when the post is not live. Body HTML is
 * server-sanitized and re-sanitized in BlogPostView before injection.
 */
export default async function BlogPostPage({ params }: Params) {
  const { slug } = await params;
  const post = await loadPost(slug);
  if (!post) notFound();

  return <BlogPostView post={post} />;
}
