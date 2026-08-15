"use client";

import Link from "next/link";
import { MapPin, Twitter, Linkedin, Facebook, Instagram, Youtube } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { brandTheme, pickLocale, type Localized, type LinkItem } from "@/config/theme";
import { useBranding } from "@/lib/branding/context";
import { useNavigation } from "@/lib/navigation/hooks";
import { safeRel } from "@/lib/navigation/api";
import type { FooterContent } from "@/lib/homepage/api";

/**
 * Site footer. Uses CMS-managed tagline + link columns when provided; otherwise falls back to the
 * built-in brand footer. Brand name, copyright, and social links come from the admin Branding
 * settings (with built-in fallbacks). Locations and legal links remain brand-level.
 */
export function LandingFooter({ content }: { content?: FooterContent }) {
  const { locale } = useI18n();
  const branding = useBranding();
  const f = brandTheme.footer;

  const brandName = pickLocale(branding.identity.brand_name, locale) || brandTheme.name;
  const copyright = pickLocale(branding.identity.copyright, locale);
  const social = branding.identity.social_links;
  const socialItems: { key: string; href: string; Icon: typeof Twitter; label: string }[] = [
    { key: "twitter", href: social.twitter ?? "", Icon: Twitter, label: "Twitter" },
    { key: "linkedin", href: social.linkedin ?? "", Icon: Linkedin, label: "LinkedIn" },
    { key: "facebook", href: social.facebook ?? "", Icon: Facebook, label: "Facebook" },
    { key: "instagram", href: social.instagram ?? "", Icon: Instagram, label: "Instagram" },
    { key: "youtube", href: social.youtube ?? "", Icon: Youtube, label: "YouTube" },
  ].filter((s) => s.href.trim() !== "");

  const description: Localized = content?.tagline ?? f.description;

  // Resolved footer link columns. Precedence: admin CMS nav (public-footer) → homepage CMS footer
  // block → built-in brandTheme columns. Every branch resolves to the same render shape so the
  // footer never breaks and external links stay hardened.
  type RenderLink = { key: string; href: string; label: string; external: boolean; target?: "_blank" | "_self"; rel?: string };
  type RenderColumn = { key: string; title: string; links: RenderLink[] };

  const cmsFooter = useNavigation("public-footer");
  const cmsLegal = useNavigation("legal-menu");

  // Legal strip. Precedence: admin CMS legal menu → built-in brandTheme legal links. CMS nodes carry
  // `url`/`id`; theme items carry `href` — normalize both to a single render shape.
  const legalItems = (cmsLegal ?? f.legal).map((l, i) => ({
    key: "id" in l ? l.id : `${l.href}-${i}`,
    href: "url" in l ? l.url : l.href,
    label: pickLocale(l.label, locale),
  }));

  const fromLocalized = (links: LinkItem[]): RenderLink[] =>
    links.map((l, i) => ({ key: `${l.href}-${i}`, href: l.href, label: pickLocale(l.label, locale), external: false }));

  const renderColumns: RenderColumn[] = cmsFooter
    ? cmsFooter.map((col) => ({
        key: col.id,
        title: pickLocale(col.label, locale),
        links: col.children.map((c) => ({
          key: c.id,
          href: c.url,
          label: pickLocale(c.label, locale),
          external: c.url_type === "external",
          target: c.target,
          rel: safeRel(c),
        })),
      }))
    : (content?.columns && content.columns.length > 0
        ? content.columns.map((c, ci) => ({
            key: `${pickLocale(c.title ?? { en: "", ar: "" }, "en")}-${ci}`,
            title: pickLocale(c.title ?? { en: "", ar: "" }, locale),
            links: fromLocalized((c.links ?? []).map((l) => ({ label: l.label, href: l.href }))),
          }))
        : f.columns.map((c, ci) => ({
            key: `${pickLocale(c.title, "en")}-${ci}`,
            title: pickLocale(c.title, locale),
            links: fromLocalized(c.links),
          })));

  return (
    <footer className="bg-primary text-primary-foreground">
      <div className="mx-auto max-w-6xl px-4 py-14">
        <div className="grid gap-10 lg:grid-cols-[1.5fr_1fr_1fr_1fr]">
          {/* Brand block */}
          <div>
            <Link href="/" className="flex items-center gap-2">
              <span className="flex size-8 items-center justify-center rounded-lg bg-gold font-serif text-sm font-bold text-gold-foreground">
                {brandName.charAt(0)}
              </span>
              <span className="font-serif text-lg font-semibold">{brandName}</span>
            </Link>
            <p className="mt-4 max-w-sm text-sm text-primary-foreground/70">{pickLocale(description, locale)}</p>
            <div className="mt-5 flex flex-wrap gap-2">
              {f.locations.map((loc) => (
                <span key={loc} className="inline-flex items-center gap-1 rounded-full border border-white/20 px-3 py-1 text-xs font-medium text-primary-foreground/90">
                  <MapPin className="size-3 text-gold" aria-hidden /> {loc}
                </span>
              ))}
            </div>
            {socialItems.length > 0 ? (
              <div className="mt-5 flex flex-wrap items-center gap-3">
                {socialItems.map(({ key, href, Icon, label }) => (
                  <a
                    key={key}
                    href={href}
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label={label}
                    className="text-primary-foreground/70 transition-colors hover:text-gold"
                  >
                    <Icon className="size-5" aria-hidden />
                  </a>
                ))}
              </div>
            ) : null}
          </div>

          {/* Link columns */}
          {renderColumns.map((col) => (
            <div key={col.key}>
              <h3 className="mb-3 text-sm font-semibold">{col.title}</h3>
              <ul className="space-y-2">
                {col.links.map((l) => (
                  <li key={l.key}>
                    {l.external ? (
                      <a
                        href={l.href}
                        target={l.target ?? "_blank"}
                        rel={l.rel ?? "noopener noreferrer"}
                        className="text-sm text-primary-foreground/70 transition-colors hover:text-primary-foreground"
                      >
                        {l.label}
                      </a>
                    ) : (
                      <Link href={l.href} className="text-sm text-primary-foreground/70 transition-colors hover:text-primary-foreground">
                        {l.label}
                      </Link>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        <div className="mt-12 flex flex-col items-center justify-between gap-4 border-t border-white/15 pt-6 text-sm text-primary-foreground/70 sm:flex-row">
          <p>© {new Date().getFullYear()} {brandName}. {copyright}</p>
          <div className="flex flex-wrap items-center gap-4">
            {legalItems.map((l) => (
              <Link key={l.key} href={l.href} className="transition-colors hover:text-primary-foreground">
                {l.label}
              </Link>
            ))}
          </div>
        </div>
      </div>
    </footer>
  );
}
