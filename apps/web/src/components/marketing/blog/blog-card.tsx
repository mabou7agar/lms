"use client";

import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { pickLocale, type Localized } from "@/config/theme";
import type { BlogPostSummary } from "@/lib/blog/api";

/**
 * Editorial blog card for the /blog grid. Renders the cover (proxied server-side; a copper→teal
 * gradient fallback when null), the category label, title, excerpt, and an author · date meta line.
 * Bilingual via useI18n/pickLocale; links to /blog/{slug}. RTL-safe (logical properties).
 */
export function BlogCard({ post }: { post: BlogPostSummary }) {
  const { locale } = useI18n();

  const title = pickLocale(post.title as Localized, locale);
  const excerpt = post.excerpt ? pickLocale(post.excerpt as Localized, locale) : null;
  const category = post.category ? pickLocale(post.category.name as Localized, locale) : null;
  const date = post.published_at
    ? new Date(post.published_at).toLocaleDateString(locale === "ar" ? "ar" : "en", {
        year: "numeric",
        month: "short",
        day: "numeric",
      })
    : null;

  return (
    <Link
      href={`/blog/${post.slug}`}
      className="group relative flex h-full flex-col overflow-hidden rounded-2xl border border-border bg-card transition-all duration-300 hover:-translate-y-1 hover:border-primary/30 hover:shadow-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
    >
      <div className="relative aspect-[16/9] overflow-hidden">
        {post.cover_image ? (
          // Native <img> is intentional: cover_image is an arbitrary, admin-supplied media URL with
          // no known intrinsic dimensions and no enumerable host, so next/image is inapplicable here
          // (matches the cms-page hero treatment). Scoped to this one element.
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={post.cover_image}
            alt=""
            className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-[1.04]"
            loading="lazy"
          />
        ) : (
          <div
            className="h-full w-full bg-gradient-to-br from-copper/25 via-secondary to-primary/25"
            aria-hidden
          />
        )}
        <div
          className="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/20 via-transparent to-transparent"
          aria-hidden
        />
      </div>

      <div className="flex flex-1 flex-col p-5">
        {category ? (
          <p className="mb-2 text-xs font-semibold uppercase tracking-[0.18em] text-copper">
            {category}
          </p>
        ) : null}

        <h3 className="line-clamp-2 font-serif text-lg font-semibold leading-tight text-foreground transition-colors group-hover:text-primary">
          {title}
        </h3>
        {excerpt ? (
          <p className="mt-1.5 line-clamp-3 text-sm leading-relaxed text-muted-foreground">
            {excerpt}
          </p>
        ) : null}

        {post.author || date ? (
          <p className="mt-4 text-xs text-muted-foreground">
            {[post.author, date].filter(Boolean).join(" · ")}
          </p>
        ) : null}

        <span className="mt-4 inline-flex items-center gap-1.5 pt-1 text-sm font-semibold text-primary">
          {locale === "ar" ? "اقرأ المقال" : "Read article"}
          <ArrowRight
            className="size-4 transition-transform duration-300 group-hover:translate-x-0.5 rtl:rotate-180 rtl:group-hover:-translate-x-0.5"
            aria-hidden
          />
        </span>
      </div>
    </Link>
  );
}
