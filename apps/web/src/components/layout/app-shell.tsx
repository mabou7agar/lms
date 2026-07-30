"use client";

import { useEffect, useState, type ReactNode } from "react";
import { usePathname } from "next/navigation";
import type { NavItem } from "@/config/nav";
import { pickLocale } from "@/config/theme";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useMediaQuery } from "@/hooks/use-media-query";
import { useNavigation } from "@/lib/navigation/hooks";
import { useFeatureFlags } from "@/lib/flags/hooks";
import { safeRel, type MenuLocation } from "@/lib/navigation/api";
import { navIcon } from "@/lib/navigation/icon-map";
import { Drawer, DrawerContent, DrawerTitle, DrawerDescription } from "@/components/ui/drawer";
import { Sidebar, type SidebarNavItem } from "./sidebar";
import { PageTransition } from "./page-transition";
import { Topbar } from "./topbar";

export interface AppShellProps {
  nav: NavItem[];
  children: ReactNode;
  brand?: string;
  /** CMS menu location driving this sidebar; falls back to `nav` when absent/empty/unreachable. */
  location?: MenuLocation;
}

/**
 * Responsive dashboard shell: persistent sidebar on desktop, drawer nav on mobile. Prefers the
 * admin-managed CMS nav for `location` when present; otherwise renders the hardcoded `nav` config
 * exactly as before (so the sidebar never disappears).
 */
export function AppShell({ nav, children, brand, location }: AppShellProps) {
  const isDesktop = useMediaQuery("(min-width: 768px)");
  const [open, setOpen] = useState(false);
  const { t, locale } = useI18n();
  const pathname = usePathname();

  // Close the mobile nav drawer on navigation — the drawer links are plain <Link>s, so without
  // this the drawer stays open covering the page after every tap.
  useEffect(() => {
    setOpen(false);
  }, [pathname]);

  const cms = useNavigation(location);
  const flags = useFeatureFlags();

  const items: SidebarNavItem[] = cms
    ? cms.map((node) => ({
        label: pickLocale(node.label, locale),
        href: node.url,
        icon: navIcon(node.icon),
        external: node.url_type === "external",
        target: node.target,
        rel: safeRel(node),
      }))
    : // Default-on: an entry shows unless its flag is explicitly OFF (route stays regardless).
      nav
        .filter((n) => !n.flag || (flags[n.flag] ?? true))
        .map((n) => ({ label: t(n.labelKey), href: n.href, icon: n.icon }));

  return (
    <div className="flex h-dvh w-full overflow-hidden">
      {isDesktop ? <Sidebar items={items} brand={brand} navLabel={t("nav.primary")} /> : null}
      <div className="flex min-w-0 flex-1 flex-col">
        <Topbar
          onMenuClick={isDesktop ? undefined : () => setOpen(true)}
          menuExpanded={open}
          menuControlsId="mobile-nav"
        />
        <main id="main-content" className="flex-1 overflow-auto p-4 md:p-6"><PageTransition>{children}</PageTransition></main>
      </div>
      {!isDesktop ? (
        <Drawer open={open} onOpenChange={setOpen}>
          <DrawerContent id="mobile-nav" className="h-[85dvh] p-0">
            {/* Accessible name/description for the dialog (screen readers) — visually hidden. */}
            <DrawerTitle className="sr-only">{t("nav.menu")}</DrawerTitle>
            <DrawerDescription className="sr-only">{t("nav.primary")}</DrawerDescription>
            <Sidebar items={items} brand={brand} navLabel={t("nav.menu")} className="w-full border-e-0" />
          </DrawerContent>
        </Drawer>
      ) : null}
    </div>
  );
}
