import type { Metadata } from "next";
import { getBlogCategories, getBlogPosts } from "@/lib/blog/api";
import { BlogListView } from "@/components/marketing/blog/blog-list-view";
import { siteConfig } from "@/config/site";

export async function generateMetadata(): Promise<Metadata> {
  const title = `Blog · ${siteConfig.name}`;
  const description =
    "Insights, guides, and news from the HElbaron academy — practical, bilingual perspectives on learning, leadership, and the future of work.";

  return {
    title,
    description,
    alternates: { canonical: "/blog" },
    openGraph: { title, description, url: "/blog", type: "website" },
    twitter: { card: "summary_large_image", title, description },
  };
}

type SearchParams = { searchParams: Promise<{ category?: string }> };

/**
 * Public blog index. Server component: fetches the published posts (optionally filtered by category)
 * and the category list, then hands off to the bilingual BlogListView. Both fetches fail safe, so a
 * backend error degrades to an empty state rather than a crash.
 */
export default async function BlogPage({ searchParams }: SearchParams) {
  const { category } = await searchParams;

  const [postsPage, categories] = await Promise.all([
    getBlogPosts({ category, perPage: 12 }),
    getBlogCategories(),
  ]);

  return (
    <BlogListView posts={postsPage.data} categories={categories} activeCategory={category} />
  );
}
