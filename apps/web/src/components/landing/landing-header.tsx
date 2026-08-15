"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { Menu } from "lucide-react";
import { useAuth } from "@/lib/auth/auth-context";
import { useI18n } from "@/lib/i18n/i18n-context";
import { brandTheme, pickLocale } from "@/config/theme";
import { useBranding } from "@/lib/branding/context";
import { useNavigation } from "@/lib/navigation/hooks";
import { safeRel } from "@/lib/navigation/api";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Drawer, DrawerContent, DrawerTitle, DrawerDescription } from "@/components/ui/drawer";
import { LangToggle } from "@/components/layout/lang-toggle";
import { ThemeToggle } from "@/components/layout/theme-toggle";
import { UserMenu } from "@/components/layout/user-menu";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";

/** Two-letter initials for the avatar fallback. */
const initials = (name: string): string => name.split(" ").map((p) => p[0]).slice(0, 2).join("").toUpperCase();

export function LandingHeader() {
  const { t, locale } = useI18n();
  const { status, user, logout } = useAuth();
  const [menuOpen, setMenuOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const pathname = usePathname();
  const branding = useBranding();
  const authed = status === "authenticated";
  const brandName = pickLocale(branding.identity.brand_name, locale) || brandTheme.name;
  const logo = branding.logos.logo_light;

  // Scroll-aware elevation: the header stays airy over the hero and condenses into a solid,
  // bordered bar once the page scrolls — a premium, less-intrusive chrome.
  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

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

  const isActive = (href: string) =>
    !href.startsWith("http") && (pathname === href || (href !== "/" && pathname.startsWith(href)));

  return (
    <header
      className={cn(
        "sticky top-0 z-40 transition-[background-color,border-color,box-shadow,backdrop-filter] duration-300",
        scrolled
          ? "border-b border-border/70 bg-background/80 shadow-[0_1px_0_0_var(--border)] backdrop-blur-xl supports-[backdrop-filter]:bg-background/70"
          : "border-b border-transparent bg-background/40 backdrop-blur-sm",
      )}
    >
      <div className="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4">
        <Link href="/" className="group flex items-center gap-2.5" aria-label={brandName}>
          {logo ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={logo} alt={brandName} width={120} height={32} className="h-8 w-auto" decoding="async" />
          ) : (
            <span className="relative flex size-9 items-center justify-center rounded-xl bg-primary font-serif text-sm font-bold text-primary-foreground shadow-sm ring-1 ring-inset ring-white/10 transition-transform duration-300 group-hover:scale-105">
              <span className="pointer-events-none absolute inset-x-1 top-1 h-1/3 rounded-t-lg bg-white/10" aria-hidden />
              {brandName.charAt(0)}
            </span>
          )}
          <span className="font-serif text-lg font-semibold tracking-tight">{brandName}</span>
        </Link>

        <nav className="hidden items-center gap-0.5 lg:flex" aria-label={t("nav.primary")}>
          {navLinks.map((l) => {
            const active = isActive(l.href);
            const cls = cn(
              "relative rounded-full px-3.5 py-2 text-sm font-medium transition-colors",
              active ? "text-foreground" : "text-muted-foreground hover:bg-muted/60 hover:text-foreground",
            );
            const dot = active ? (
              <span className="pointer-events-none absolute inset-x-3.5 -bottom-px h-0.5 rounded-full bg-copper" aria-hidden />
            ) : null;
            return l.external ? (
              <a key={l.key} href={l.href} target={l.target ?? "_blank"} rel={l.rel ?? "noopener noreferrer"} className={cls}>
                {l.label}
                {dot}
              </a>
            ) : (
              <Link key={l.key} href={l.href} className={cls} aria-current={active ? "page" : undefined}>
                {l.label}
                {dot}
              </Link>
            );
          })}
        </nav>

        <div className="ms-auto flex items-center gap-1.5">
          <div className="hidden items-center gap-0.5 sm:flex">
            <LangToggle />
            <ThemeToggle />
          </div>
          <span className="mx-1 hidden h-5 w-px bg-border sm:block" aria-hidden />
          {authed ? (
            <div className="hidden sm:block">
              <UserMenu showName />
            </div>
          ) : (
            <>
              <Button asChild size="sm" variant="ghost" className="hidden font-medium sm:inline-flex">
                <Link href="/login">{pickLocale(brandTheme.ctas.signIn, locale)}</Link>
              </Button>
              <Button asChild size="sm" className="shine relative hidden overflow-hidden shadow-sm sm:inline-flex">
                <Link href="/register">{pickLocale(brandTheme.ctas.startFree, locale)}</Link>
              </Button>
            </>
          )}

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
          <nav className="flex flex-col gap-0.5" aria-label={t("nav.primary")}>
            {navLinks.map((l) =>
              l.external ? (
                <a
                  key={l.key}
                  href={l.href}
                  target={l.target ?? "_blank"}
                  rel={l.rel ?? "noopener noreferrer"}
                  className="rounded-lg px-3 py-2.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground"
                  onClick={() => setMenuOpen(false)}
                >
                  {l.label}
                </a>
              ) : (
                <Link
                  key={l.key}
                  href={l.href}
                  className="rounded-lg px-3 py-2.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground"
                  onClick={() => setMenuOpen(false)}
                >
                  {l.label}
                </Link>
              ),
            )}
          </nav>
          <div className="my-4 h-px bg-border" aria-hidden />
          <div className="flex items-center gap-2">
            <LangToggle />
            <ThemeToggle />
          </div>
          <div className="mt-4 flex flex-col gap-2">
            {authed && user ? (
              <>
                <div className="flex items-center gap-2.5 rounded-lg px-1 py-1.5">
                  <Avatar className="size-9">
                    <AvatarFallback>{initials(user.name)}</AvatarFallback>
                  </Avatar>
                  <div className="flex min-w-0 flex-col">
                    <span className="truncate text-sm font-medium">{user.name}</span>
                    <span className="truncate text-xs text-muted-foreground">{user.email}</span>
                  </div>
                </div>
                <Button asChild variant="outline" onClick={() => setMenuOpen(false)}>
                  <Link href="/dashboard">{t("nav.dashboard")}</Link>
                </Button>
                <Button
                  variant="ghost"
                  onClick={() => {
                    setMenuOpen(false);
                    void logout();
                  }}
                >
                  {t("common.signOut")}
                </Button>
              </>
            ) : (
              <>
                <Button asChild variant="outline" onClick={() => setMenuOpen(false)}>
                  <Link href="/login">{pickLocale(brandTheme.ctas.signIn, locale)}</Link>
                </Button>
                <Button asChild className="shine relative overflow-hidden" onClick={() => setMenuOpen(false)}>
                  <Link href="/register">{pickLocale(brandTheme.ctas.startFree, locale)}</Link>
                </Button>
              </>
            )}
          </div>
        </DrawerContent>
      </Drawer>
    </header>
  );
}
