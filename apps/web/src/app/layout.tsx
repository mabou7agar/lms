import type { Metadata } from "next";
import { cookies } from "next/headers";
import { Inter, Fraunces, IBM_Plex_Sans_Arabic, IBM_Plex_Mono } from "next/font/google";
import { defaultLocale, isLocale, localeCookieName, localeDirection, type Locale } from "@/lib/i18n/config";
import { siteConfig } from "@/config/site";
import { getBranding, type Branding } from "@/lib/branding/api";
import { getFeatureFlags, type FeatureFlags } from "@/lib/flags/api";
import { brandThemeCss, googleFontCss, googleFontHref } from "@/lib/branding/css";
import { Providers } from "./providers";
import "./globals.css";
import { jsonLdScript } from "@/lib/seo/json-ld";

const inter = Inter({ subsets: ["latin"], variable: "--font-inter", display: "swap" });
const fraunces = Fraunces({
  subsets: ["latin"],
  variable: "--font-fraunces",
  display: "swap",
  style: ["normal", "italic"],
  axes: ["opsz"],
});
// Arabic UI/heading typeface (Fraunces + Inter lack Arabic glyphs). Exposed as --font-arabic
// and woven into --font-sans; RTL headings fall back to it. Kept separate from the Theme
// Manager's --font-sans override, which still wins.
const ibmPlexArabic = IBM_Plex_Sans_Arabic({
  subsets: ["arabic"],
  weight: ["400", "500", "600", "700"],
  variable: "--font-ibm-plex-arabic",
  display: "swap",
});
// Editorial technical mono for course-cover metadata (codes, coordinates, folio/press marks).
// Exposed as --font-mono and consumed via Tailwind `font-mono`.
const ibmPlexMono = IBM_Plex_Mono({
  subsets: ["latin"],
  weight: ["400", "500", "600"],
  variable: "--font-ibm-plex-mono",
  display: "swap",
});

/**
 * Base metadata, branded from the admin Branding settings (falls back to siteConfig / defaults). The
 * homepage SEO block (Homepage CMS) still overrides title/description on `/` via its own metadata.
 */
export async function generateMetadata(): Promise<Metadata> {
  const branding = await getBranding();
  const name = branding.identity.brand_name.en || siteConfig.name;
  const favicon = branding.logos.favicon;
  const appleIcon = branding.logos.apple_icon;

  return {
    metadataBase: new URL(siteConfig.url),
    title: { default: `${name} · Bilingual Professional Academy`, template: `%s · ${name}` },
    description: siteConfig.description,
    applicationName: name,
    alternates: { canonical: "/" },
    ...(favicon || appleIcon
      ? {
          icons: {
            ...(favicon ? { icon: favicon } : {}),
            ...(appleIcon ? { apple: appleIcon } : {}),
          },
        }
      : {}),
    openGraph: {
      type: "website",
      siteName: name,
      title: name,
      description: siteConfig.description,
      url: siteConfig.url,
      locale: "en",
      alternateLocale: ["ar"],
    },
    twitter: {
      card: "summary_large_image",
      title: name,
      description: siteConfig.description,
    },
    robots: { index: true, follow: true },
  };
}

/** Organization schema.org markup for richer search results (rendered once in the root layout). */
function organizationJsonLd(name: string): string {
  return jsonLdScript({
    "@context": "https://schema.org",
    "@type": "EducationalOrganization",
    name,
    url: siteConfig.url,
    description: siteConfig.description,
  });
}

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const cookieStore = await cookies();
  const cookieLocale = cookieStore.get(localeCookieName)?.value;
  const locale: Locale = isLocale(cookieLocale) ? cookieLocale : defaultLocale;

  const branding: Branding = await getBranding();
  const flags: FeatureFlags = await getFeatureFlags();
  const themeCss = brandThemeCss(branding);
  const fontFamily = branding.theme.google_font;
  const fontHref = googleFontHref(fontFamily);

  return (
    <html lang={locale} dir={localeDirection[locale]} suppressHydrationWarning>
      <head>
        {/* Admin-controlled white-label theme: overrides globals.css CSS variables on :root/.dark.
            Safe — only presentation values (colours/radius) from trusted admin settings. */}
        {themeCss ? <style id="brand-theme" dangerouslySetInnerHTML={{ __html: themeCss }} /> : null}
        {fontHref ? <link rel="stylesheet" href={fontHref} /> : null}
        {fontFamily ? <style id="brand-font" dangerouslySetInnerHTML={{ __html: googleFontCss(fontFamily) }} /> : null}
      </head>
      <body className={`${inter.variable} ${fraunces.variable} ${ibmPlexArabic.variable} ${ibmPlexMono.variable} font-sans antialiased`}>
        {/* Static, app-controlled JSON-LD (no user input). */}
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: organizationJsonLd(branding.identity.brand_name.en || siteConfig.name) }}
        />
        <a
          href="#main-content"
          className="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-primary-foreground"
        >
          Skip to content
        </a>
        <Providers initialLocale={locale} branding={branding} flags={flags}>
          {children}
        </Providers>
      </body>
    </html>
  );
}
