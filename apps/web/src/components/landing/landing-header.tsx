"use client";

import { useState } from "react";
import Link from "next/link";
import { Menu } from "lucide-react";
import { useAuth } from "@/lib/auth/auth-context";
import { useI18n } from "@/lib/i18n/i18n-context";
import { brandTheme, pickLocale } from "@/config/theme";
import { useBranding } from "@/lib/branding/context";
import { useNavigation } from "@/lib/navigation/hooks";
import { safeRel } from "@/lib/navigation/api";
import { Button } from "@/components/ui/button";
import { Drawer, DrawerContent, DrawerTitle, DrawerDescription } from "@/components/ui/drawer";
import { LangToggle } from "@/components/layout/lang-toggle";
import { ThemeToggle } from "@/components/layout/theme-toggle";

export function LandingHeader() {
  const { t, locale } = useI18n();
  const { status } = useAuth();
  const [menuOpen, setMenuOpen] = useState(false);
  const branding = useBranding();
  const authed = status === "authenticated";
  // Brand name comes from the admin Branding settings; falls back to the built-in brand.
  const brandName = pickLocale(branding.identity.brand_name, locale) || brandTheme.name;
  const logo = branding.logos.logo_light;

  // Prefer the admin-managed CMS header nav; fall back to the built-in brandTheme.nav.
  const cmsNav = useNavigation("public-header");
  const navLinks = cmsNav
    ? cmsNav.map((n) => ({
        key: n.id,
        href: n.url,
        label: pickLocale(n.label, locale),
        external: n.url_type === "external",
        target: n.target,
        rel: safeRel(n),
      }))
    : brandTheme.nav.map((l) => ({
        key: l.href,
        href: l.href,
        label: pickLocale(l.label, locale),
        external: false as boolean,
        target: undefined as "_blank" | "_self" | undefined,
        rel: undefined as string | undefined,
      }));

  return (
    <header className="sticky top-0 z-40 border-b bg-background/85 backdrop-blur">
      <div className="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4">
        <Link href="/" className="flex items-center gap-2">
          {logo ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={logo} alt={brandName} width={120} height={32} className="h-8 w-auto" decoding="async" />
          ) : (
            <span className="flex size-8 items-center justify-center rounded-lg bg-primary font-serif text-sm font-bold text-primary-foreground">
              {brandName.charAt(0)}
            </span>
          )}
          <span className="font-serif text-lg font-semibold tracking-tight">{brandName}</span>
        </Link>

        <nav className="hidden items-center gap-1 lg:flex" aria-label={t("nav.primary")}>
          {navLinks.map((l) =>
            l.external ? (
              <a
                key={l.key}
                href={l.href}
                target={l.target ?? "_blank"}
                rel={l.rel ?? "noopener noreferrer"}
                className="rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
              >
                {l.label}
              </a>
            ) : (
              <Link
                key={l.key}
                href={l.href}
                className="rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
              >
                {l.label}
              </Link>
            ),
          )}
        </nav>

        <div className="ms-auto flex items-center gap-1">
          <LangToggle />
          <ThemeToggle />
          <Button asChild size="sm" variant="ghost" className="hidden sm:inline-flex">
            <Link href={authed ? "/dashboard" : "/login"}>{pickLocale(brandTheme.ctas.signIn, locale)}</Link>
          </Button>
          <Button asChild size="sm" className="hidden sm:inline-flex">
            <Link href={authed ? "/dashboard" : "/register"}>{pickLocale(brandTheme.ctas.startFree, locale)}</Link>
          </Button>

          {/* Mobile: the primary nav is hidden below lg, so surface it (and the CTAs) in a drawer. */}
          <Button
            type="button"
            size="icon"
            variant="ghost"
            className="lg:hidden"
            aria-label={t("nav.openMenu")}
            aria-expanded={menuOpen}
            aria-controls="landing-mobile-nav"
            onClick={() => setMenuOpen(true)}
          >
            <Menu className="size-5" aria-hidden />
          </Button>
        </div>
      </div>

      <Drawer open={menuOpen} onOpenChange={setMenuOpen}>
        <DrawerContent id="landing-mobile-nav" className="p-6">
          <DrawerTitle className="sr-only">{t("nav.menu")}</DrawerTitle>
          <DrawerDescription className="sr-only">{t("nav.primary")}</DrawerDescription>
          <nav className="flex flex-col gap-1" aria-label={t("nav.primary")}>
            {navLinks.map((l) =>
              l.external ? (
                <a
                  key={l.key}
                  href={l.href}
                  target={l.target ?? "_blank"}
                  rel={l.rel ?? "noopener noreferrer"}
                  className="rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                  onClick={() => setMenuOpen(false)}
                >
                  {l.label}
                </a>
              ) : (
                <Link
                  key={l.key}
                  href={l.href}
                  className="rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                  onClick={() => setMenuOpen(false)}
                >
                  {l.label}
                </Link>
              ),
            )}
          </nav>
          <div className="mt-4 flex flex-col gap-2">
            <Button asChild variant="ghost" onClick={() => setMenuOpen(false)}>
              <Link href={authed ? "/dashboard" : "/login"}>{pickLocale(brandTheme.ctas.signIn, locale)}</Link>
            </Button>
            <Button asChild onClick={() => setMenuOpen(false)}>
              <Link href={authed ? "/dashboard" : "/register"}>{pickLocale(brandTheme.ctas.startFree, locale)}</Link>
            </Button>
          </div>
        </DrawerContent>
      </Drawer>
    </header>
  );
}
