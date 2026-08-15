"use client";

import Link from "next/link";
import { Menu, ShoppingCart } from "lucide-react";
import type { ReactNode } from "react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { Button } from "@/components/ui/button";
import { LangToggle } from "./lang-toggle";
import { ThemeToggle } from "./theme-toggle";
import { UserMenu } from "./user-menu";

export interface TopbarProps {
  onMenuClick?: () => void;
  start?: ReactNode;
  /** Reflects the mobile-nav drawer open state for `aria-expanded`. */
  menuExpanded?: boolean;
  /** id of the element the menu button toggles, for `aria-controls`. */
  menuControlsId?: string;
}

export function Topbar({ onMenuClick, start, menuExpanded, menuControlsId }: TopbarProps) {
  const { t } = useI18n();

  return (
    <header className="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-border/70 bg-background/80 px-4 backdrop-blur-md supports-[backdrop-filter]:bg-background/70 md:px-6" aria-label={t("nav.topbar")}>
      <div className="flex items-center gap-2">
        {onMenuClick ? (
          <Button
            variant="ghost"
            size="icon"
            className="md:hidden"
            aria-label={t("nav.openMenu")}
            aria-haspopup="dialog"
            aria-expanded={menuExpanded ?? false}
            aria-controls={menuControlsId}
            onClick={onMenuClick}
          >
            <Menu className="size-5" aria-hidden />
          </Button>
        ) : null}
        {start}
      </div>
      <div className="flex items-center gap-1">
        <Button variant="ghost" size="icon" className="rounded-full" aria-label={t("commerce.nav.cart")} asChild>
          <Link href="/cart">
            <ShoppingCart className="size-5" aria-hidden />
          </Link>
        </Button>
        <LangToggle />
        <ThemeToggle />
        <UserMenu />
      </div>
    </header>
  );
}
