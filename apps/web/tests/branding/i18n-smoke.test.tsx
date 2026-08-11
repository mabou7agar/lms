import { describe, expect, it, vi, beforeEach } from "vitest";
import { Suspense } from "react";
import { render, screen } from "@testing-library/react";
import { I18nProvider } from "@/lib/i18n/i18n-context";
import type { Locale } from "@/lib/i18n/config";
import type { Branding } from "@/lib/branding/api";

const { useOrgBranding, useOrgDomains, useAuth } = vi.hoisted(() => ({
  useOrgBranding: vi.fn(),
  useOrgDomains: vi.fn(),
  useAuth: vi.fn(),
}));

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/auth/auth-context", () => ({ useAuth }));
vi.mock("@/lib/branding/org-hooks", () => ({
  useOrgBranding,
  useOrgDomains,
  useUpdateOrgBranding: () => ({ mutate: vi.fn(), isPending: false }),
  useCreateOrgDomain: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteOrgDomain: () => ({ mutate: vi.fn(), isPending: false }),
  useVerifyOrgDomain: () => ({ mutate: vi.fn(), isPending: false }),
}));

import ManagerBrandingPage from "@/app/(enterprise)/manager/branding/page";

const ok = (data: unknown) => ({ isPending: false, isError: false, refetch: vi.fn(), data });

const BRANDING = {
  identity: { brand_name: { en: "Acme", ar: "أكمي" } },
  logos: { logo_light: "", favicon: "" },
  theme: { colors: { primary: "#0F766E", secondary: "#134E4A" } },
} as unknown as Branding;

function renderAt(locale: Locale) {
  return render(
    <I18nProvider initialLocale={locale}>
      <Suspense fallback={null}>
        <ManagerBrandingPage />
      </Suspense>
    </I18nProvider>,
  );
}

describe("Manager brand settings i18n smoke", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useOrgBranding.mockReturnValue(ok(BRANDING));
    useOrgDomains.mockReturnValue(ok([]));
    useAuth.mockReturnValue({ status: "authenticated", user: { roles: ["admin"] } });
  });

  it("renders in English", () => {
    renderAt("en");
    expect(screen.getByRole("heading", { name: "Brand & domains" })).toBeInTheDocument();
  });

  it("renders in Arabic", () => {
    renderAt("ar");
    expect(screen.getByRole("heading", { name: "الهوية والنطاقات" })).toBeInTheDocument();
  });
});
