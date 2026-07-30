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

  return (
    <aside className={cn("flex h-full w-64 flex-col border-e bg-card", className)}>
      <div className="flex h-16 items-center px-6 text-lg font-semibold">{brand}</div>
      <nav className="flex-1 space-y-1 px-3 py-2" aria-label={navLabel}>
        {items.map((item) => {
          const active = !item.external && item.href === activeHref;
          const Icon = item.icon;
          const className = cn(
            "flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors",
            active
              ? "bg-primary text-primary-foreground"
              : "text-muted-foreground hover:bg-accent hover:text-accent-foreground",
          );
          const content = (
            <>
              {Icon ? <Icon className="size-4 shrink-0" aria-hidden /> : null}
              <span>{item.label}</span>
            </>
          );

          // External items open in a new tab with a hardened rel; internal items use the router.
          return item.external ? (
            <a
              key={`${item.href}-${item.label}`}
              href={item.href}
              target={item.target ?? "_blank"}
              rel={item.rel ?? "noopener noreferrer"}
              className={className}
            >
              {content}
            </a>
          ) : (
            <Link
              key={`${item.href}-${item.label}`}
              href={item.href}
              aria-current={active ? "page" : undefined}
              className={className}
            >
              {content}
            </Link>
          );
        })}
      </nav>
    </aside>
  );
}
