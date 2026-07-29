"use client";

import DOMPurify from "isomorphic-dompurify";
import { useI18n } from "@/lib/i18n/i18n-context";
import { pickLocale, type Localized } from "@/config/theme";
import { Reveal } from "@/components/landing/reveal";
import type { StaticPage } from "@/lib/pages/api";

/**
 * Renders a CMS-managed static page (title / excerpt / hero / sanitized HTML body) with the
 * template-appropriate editorial layout, bilingual via useI18n. The body is already sanitized
 * server-side; it is sanitized AGAIN here client-side (defense in depth) before injection, mirroring
 * how lesson-content renders trusted-but-verified HTML.
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

export function CmsPage({ page }: { page: StaticPage }) {
  const { locale } = useI18n();

  const title = pickLocale(page.title as Localized, locale);
  const excerpt = page.excerpt ? pickLocale(page.excerpt as Localized, locale) : null;
  const bodyHtml = sanitizeBodyHtml(page.body?.[locale] ?? page.body?.en ?? "");
  const isLegal = page.template === "legal";

  return (
    <Reveal className="mx-auto max-w-3xl py-6">
      <p className="text-xs font-semibold uppercase tracking-[0.22em] text-copper">HElbaron</p>
      <h1 className="mt-2 font-serif text-3xl font-semibold tracking-tight sm:text-4xl">{title}</h1>
      {excerpt ? <p className="mt-4 text-muted-foreground sm:text-lg">{excerpt}</p> : null}

      {page.hero_image ? (
        // Native <img> is intentional and next/image is genuinely inapplicable here: hero_image is an
        // arbitrary, admin-supplied CMS URL with no known intrinsic dimensions and no enumerable host.
        // next/image would require explicit width/height (or `fill` + a sized wrapper — changing the
        // current intrinsic-ratio layout and CLS) plus an images.remotePatterns allow-list we cannot
        // define for user-entered hosts. Scoped to this one element only.
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={page.hero_image}
          alt=""
          className="mt-6 w-full rounded-2xl border object-cover"
          loading="lazy"
        />
      ) : null}

      <div
        className={
          isLegal
            ? "prose prose-sm mt-8 max-w-none dark:prose-invert"
            : "prose mt-8 max-w-none dark:prose-invert"
        }
        // API HTML is sanitized server-side AND re-sanitized above before injection.
        dangerouslySetInnerHTML={{ __html: bodyHtml }}
      />
    </Reveal>
  );
}
