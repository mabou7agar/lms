"use client";

import Link from "next/link";
import { ArrowLeft, ArrowRight } from "lucide-react";
import { useI18n } from "@/lib/i18n/i18n-context";
import { LangToggle } from "@/components/layout/lang-toggle";
import { ThemeToggle } from "@/components/layout/theme-toggle";

/**
 * Minimal, focused learner chrome for the course player surface. Replaces the
 * marketing header/footer: a slim bar with a back-to-dashboard link plus the
 * locale + theme toggles. RTL-aware (the back arrow flips; `ms-auto` mirrors).
 */
export function LearnerFrameHeader() {
  const { t, dir } = useI18n();
  const Back = dir === "rtl" ? ArrowRight : ArrowLeft;
  return (
    <header className="sticky top-0 z-40 border-b border-border/70 bg-background/80 backdrop-blur-xl supports-[backdrop-filter]:bg-background/70">
      <div className="mx-auto flex h-14 max-w-6xl items-center gap-4 px-4">
        <Link
          href="/dashboard"
          className="flex items-center gap-2 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
        >
          <Back className="size-4" aria-hidden />
          <span>{t("nav.dashboard")}</span>
        </Link>
        <div className="ms-auto flex items-center gap-0.5">
          <LangToggle />
          <ThemeToggle />
        </div>
      </div>
    </header>
  );
}
