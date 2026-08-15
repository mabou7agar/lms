"use client";

import Link from "next/link";
import { useI18n } from "@/lib/i18n/i18n-context";
import { pickLocale, type Localized } from "@/config/theme";
import { Reveal } from "@/components/landing/reveal";
import { BlogCard } from "./blog-card";
import type { BlogCategory, BlogPostSummary } from "@/lib/blog/api";

/**
 * Client view for the /blog list page: bilingual heading, optional category chips (links that
 * filter via ?category=), and a responsive grid of BlogCard. Handles the empty state. Data is
 * fetched server-side and passed in, so this stays presentational; the locale drives all copy.
 */
export function BlogListView({
  posts,
  categories,
  activeCategory,
}: {
  posts: BlogPostSummary[];
  categories: BlogCategory[];
  activeCategory?: string;
}) {
  const { locale } = useI18n();

  const heading = locale === "ar" ? "المدوّنة" : "Blog";
  const subtitle =
    locale === "ar"
      ? "رؤى وأدلة وأخبار من أكاديمية HElbaron — بالعربية والإنجليزية."
      : "Insights, guides, and news from the HElbaron academy — in Arabic and English.";
  const allLabel = locale === "ar" ? "الكل" : "All";
  const emptyLabel =
    locale === "ar" ? "لا توجد مقالات منشورة بعد." : "No published articles yet.";

  const chipBase =
    "rounded-full border px-4 py-1.5 text-sm font-medium transition-colors";
  const chipActive = "border-primary bg-primary text-primary-foreground";
  const chipIdle = "border-border bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground";

  return (
    <Reveal className="py-4">
      <header className="mx-auto max-w-3xl text-center">
        <p className="text-xs font-semibold uppercase tracking-[0.22em] text-copper">HElbaron</p>
        <h1 className="mt-2 font-serif text-3xl font-semibold tracking-tight sm:text-4xl">
          {heading}
        </h1>
        <p className="mt-4 text-muted-foreground sm:text-lg">{subtitle}</p>
      </header>

      {categories.length > 0 ? (
        <nav className="mt-8 flex flex-wrap justify-center gap-2" aria-label={heading}>
          <Link
            href="/blog"
            className={`${chipBase} ${!activeCategory ? chipActive : chipIdle}`}
            aria-current={!activeCategory ? "page" : undefined}
          >
            {allLabel}
          </Link>
          {categories.map((category) => {
            const isActive = activeCategory === category.slug;
            return (
              <Link
                key={category.id}
                href={`/blog?category=${encodeURIComponent(category.slug)}`}
                className={`${chipBase} ${isActive ? chipActive : chipIdle}`}
                aria-current={isActive ? "page" : undefined}
              >
                {pickLocale(category.name as Localized, locale)}
              </Link>
            );
          })}
        </nav>
      ) : null}

      {posts.length > 0 ? (
        <div className="mt-10 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {posts.map((post) => (
            <BlogCard key={post.id} post={post} />
          ))}
        </div>
      ) : (
        <p className="mt-16 text-center text-muted-foreground">{emptyLabel}</p>
      )}
    </Reveal>
  );
}
