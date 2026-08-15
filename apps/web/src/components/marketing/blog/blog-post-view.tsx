"use client";

import DOMPurify from "isomorphic-dompurify";
import { Clock } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { pickLocale, type Localized } from "@/config/theme";
import { Reveal } from "@/components/landing/reveal";
import type { BlogPost } from "@/lib/blog/api";

/**
 * Renders a single blog post (hero cover + category + title + meta + sanitized HTML body) in an
 * editorial layout, bilingual via useI18n. The body is already sanitized server-side; it is
 * sanitized AGAIN here client-side (defense in depth) before injection, mirroring how cms-page
 * renders trusted-but-verified HTML.
 */
function sanitizeBodyHtml(dirty: string): string {
  return DOMPurify.sanitize(dirty, {
    ALLOWED_TAGS: [
      "a", "abbr", "b", "blockquote", "br", "caption", "code", "div", "em", "figcaption", "figure",
      "h1", "h2", "h3", "h4", "h5", "h6", "hr", "i", "img", "li", "mark", "ol", "p", "pre", "s",
      "small", "span", "strong", "sub", "sup", "table", "tbody", "td", "tfoot", "th", "thead", "tr", "u", "ul",
    ],
    ALLOWED_ATTR: ["href", "src", "alt", "title", "target", "rel", "class", "dir", "lang", "colspan", "rowspan", "width", "height", "start", "type"],
    FORBID_TAGS: ["script", "style", "iframe", "object", "embed", "form"],
  });
}

export function BlogPostView({ post }: { post: BlogPost }) {
  const { locale } = useI18n();

  const title = pickLocale(post.title as Localized, locale);
  const excerpt = post.excerpt ? pickLocale(post.excerpt as Localized, locale) : null;
  const category = post.category ? pickLocale(post.category.name as Localized, locale) : null;
  const bodyHtml = sanitizeBodyHtml(post.body?.[locale] ?? post.body?.en ?? "");
  const date = post.published_at
    ? new Date(post.published_at).toLocaleDateString(locale === "ar" ? "ar" : "en", {
        year: "numeric",
        month: "long",
        day: "numeric",
      })
    : null;
  const readLabel = post.reading_minutes
    ? locale === "ar"
      ? `${post.reading_minutes} دقائق قراءة`
      : `${post.reading_minutes} min read`
    : null;

  return (
    <Reveal className="mx-auto max-w-3xl py-6">
      {category ? (
        <p className="text-xs font-semibold uppercase tracking-[0.22em] text-copper">{category}</p>
      ) : (
        <p className="text-xs font-semibold uppercase tracking-[0.22em] text-copper">HElbaron</p>
      )}
      <h1 className="mt-2 font-serif text-3xl font-semibold tracking-tight sm:text-4xl">{title}</h1>
      {excerpt ? <p className="mt-4 text-muted-foreground sm:text-lg">{excerpt}</p> : null}

      {post.author || date || readLabel ? (
        <div className="mt-5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
          {post.author ? <span className="font-medium text-foreground">{post.author}</span> : null}
          {date ? <span>{date}</span> : null}
          {readLabel ? (
            <span className="inline-flex items-center gap-1">
              <Clock className="size-3.5" aria-hidden /> {readLabel}
            </span>
          ) : null}
        </div>
      ) : null}

      {post.cover_image ? (
        // Native <img> is intentional: cover_image is an arbitrary, admin-supplied media URL with no
        // known intrinsic dimensions and no enumerable host (matches cms-page's hero treatment).
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={post.cover_image}
          alt=""
          className="mt-6 aspect-[16/9] w-full rounded-2xl border object-cover"
          loading="lazy"
        />
      ) : null}

      <div
        className="prose mt-8 max-w-none dark:prose-invert"
        // API HTML is sanitized server-side AND re-sanitized above before injection.
        dangerouslySetInnerHTML={{ __html: bodyHtml }}
      />
    </Reveal>
  );
}
