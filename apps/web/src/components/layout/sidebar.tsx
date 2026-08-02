"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import type { LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";

/** A sidebar entry with an already-resolved label (from the CMS nav or the i18n dictionary). */
export type SidebarNavItem = {
  label: string;
  href: string;
  icon?: LucideIcon;
  target?: "_blank" | "_self";
  rel?: string;
  external?: boolean;
};

export interface SidebarProps {
  items: SidebarNavItem[];
  brand?: string;
  className?: string;
  /** Accessible name for the nav landmark (distinguishes the desktop rail from the mobile drawer). */
  navLabel?: string;
}

/** Direction-agnostic vertical nav. Icons + resolved labels; active state by path prefix. */
export function Sidebar({ items, brand = "HElbaron", className, navLabel = "Primary" }: SidebarProps) {
  const pathname = usePathname();

  // Only the LONGEST matching href is "active" — otherwise a parent (e.g. /teach) and its child
  // (/teach/courses) both highlight and both emit aria-current="page", so a screen reader announces
  // two current pages. Pick the most specific match.
  const activeHref =
    items
      .filter((i) => !i.external && (pathname === i.href || pathname.startsWith(`${i.href}/`)))
      .reduce<string | null>((best, i) => (i.href.length > (best?.length ?? -1) ? i.href : best), null);

  const mark = brand.trim().charAt(0).toUpperCase() || "H";

  return (
    <aside className={cn("flex h-full w-64 flex-col border-e border-border/70 bg-card", className)}>
      {/* Brand */}
      <div className="flex h-16 items-center gap-3 px-5">
        <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-primary font-serif text-lg font-bold text-primary-foreground shadow-sm shadow-primary/20">
          {mark}
        </span>
        <span className="truncate font-serif text-lg font-semibold tracking-tight">{brand}</span>
      </div>
      <div className="mx-5 h-px bg-gradient-to-r from-border/70 to-transparent" aria-hidden />

      <nav className="flex-1 space-y-1 overflow-y-auto px-3 py-4" aria-label={navLabel}>
        {items.map((item) => {
          const active = !item.external && item.href === activeHref;
          const Icon = item.icon;
          const linkClass = cn(
            "group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-all duration-200",
            active
              ? "bg-copper/[0.08] text-foreground"
              : "text-muted-foreground hover:bg-accent/60 hover:text-foreground",
          );
          const content = (
            <>
              {/* Active start-indicator bar (logical inset-start, RTL-safe) */}
              <span
                className={cn(
                  "absolute inset-y-2 start-0 w-[3px] rounded-full bg-copper transition-opacity duration-200",
                  active ? "opacity-100" : "opacity-0",
                )}
                aria-hidden
              />
              {Icon ? (
                <Icon
                  className={cn(
                    "size-[1.15rem] shrink-0 transition-colors",
                    active ? "text-copper" : "text-muted-foreground group-hover:text-foreground",
                  )}
                  aria-hidden
                />
              ) : null}
              <span className="truncate">{item.label}</span>
            </>
          );

          // External items open in a new tab with a hardened rel; internal items use the router.
          return item.external ? (
            <a
              key={`${item.href}-${item.label}`}
              href={item.href}
              target={item.target ?? "_blank"}
              rel={item.rel ?? "noopener noreferrer"}
              className={linkClass}
            >
              {content}
            </a>
          ) : (
            <Link
              key={`${item.href}-${item.label}`}
              href={item.href}
              aria-current={active ? "page" : undefined}
              className={linkClass}
            >
              {content}
            </Link>
          );
        })}
      </nav>
    </aside>
  );
}
