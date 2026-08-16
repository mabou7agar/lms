"use client";

import type { ReactNode } from "react";
import { usePathname } from "next/navigation";
import { GraduationCap, Users, Award } from "lucide-react";
import { RequireGuest } from "@/lib/auth/guards";
import { useI18n } from "@/lib/i18n/i18n-context";

/**
 * Auth routes that a SIGNED-IN user is supposed to reach. Everything else in this group is
 * guest-only, so an authenticated visitor still cannot open login or register.
 */
const AUTHENTICATED_AUTH_ROUTES = new Set(["/verify-email"]);

/**
 * Premium split-layout for the authentication surfaces: an editorial brand panel on large
 * screens (dot-grid depth, serif brand, real product value props — no fabricated testimonials)
 * and the form column on the inline-end. RTL-safe via logical properties; the panel collapses
 * on mobile so the form leads.
 */
export default function AuthLayout({ children }: { children: ReactNode }) {
  const { locale } = useI18n();
  const pathname = usePathname();
  const L = (en: string, ar: string) => (locale === "ar" ? ar : en);

  const points = [
    { icon: GraduationCap, label: L("Bilingual, MENA-focused business academy", "أكاديمية أعمال ثنائية اللغة للمنطقة") },
    { icon: Users, label: L("Live cohorts, workshops and self-paced courses", "أفواج مباشرة وورش ودورات ذاتية") },
    { icon: Award, label: L("Verifiable certificates on completion", "شهادات قابلة للتحقق عند الإتمام") },
  ];

  // Email verification is the one auth surface that belongs to a SIGNED-IN user: registration logs
  // the account in and sends it straight here, and the page itself reads the session to submit the
  // code. Guarding it as guest-only made it unreachable the moment it was needed — the guard bounced
  // the freshly-registered user to the homepage. It keeps the same shell, just not the guest guard.
  // Every other auth page stays guest-only, so an authenticated user still cannot see login/register.
  const content = (
    <div className="grid min-h-dvh lg:grid-cols-2">
        {/* Brand panel (large screens) */}
        <aside className="relative hidden overflow-hidden bg-primary text-primary-foreground lg:flex lg:flex-col lg:justify-between lg:p-12">
          <div className="pointer-events-none absolute inset-0 opacity-[0.15] [background-image:radial-gradient(oklch(1_0_0/0.5)_1px,transparent_1px)] [background-size:24px_24px] [mask-image:radial-gradient(80%_80%_at_30%_10%,#000,transparent_75%)]" aria-hidden />
          <div className="pointer-events-none absolute -end-16 top-1/3 size-72 rounded-full bg-gold/10 blur-3xl" aria-hidden />

          <div className="relative flex items-center gap-3">
            <span className="grid size-10 place-items-center rounded-xl bg-primary-foreground/10 font-serif text-lg font-bold">H</span>
            <span className="font-serif text-xl font-semibold">HElbaron</span>
          </div>

          <div className="relative">
            <p className="text-xs font-semibold uppercase tracking-[0.22em] text-gold">{L("Interactive Academy", "أكاديمية تفاعلية")}</p>
            <h2 className="mt-3 max-w-md font-serif text-4xl font-semibold leading-[1.1]">
              {L("Master the core.", "أتقن الأساس.")}{" "}
              <span className="italic text-gold">{L("Lead the future.", "قُد المستقبل.")}</span>
            </h2>
            <ul className="mt-8 space-y-4">
              {points.map((p) => (
                <li key={p.label} className="flex items-center gap-3">
                  <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-primary-foreground/10">
                    <p.icon className="size-4" aria-hidden />
                  </span>
                  <span className="text-sm text-primary-foreground/85">{p.label}</span>
                </li>
              ))}
            </ul>
          </div>

          <p className="relative text-xs text-primary-foreground/60">© 2026 HElbaron</p>
        </aside>

        {/* Form column */}
        <main className="flex items-center justify-center p-4 sm:p-8">
          <div className="w-full max-w-md">{children}</div>
        </main>
      </div>
  );

  return AUTHENTICATED_AUTH_ROUTES.has(pathname ?? "")
    ? content
    : <RequireGuest redirectTo="/">{content}</RequireGuest>;
}
