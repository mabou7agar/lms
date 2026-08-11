import { describe, expect, it, vi, beforeEach } from "vitest";
import { Suspense } from "react";
import { render, screen } from "@testing-library/react";
import { I18nProvider } from "@/lib/i18n/i18n-context";
import type { Locale } from "@/lib/i18n/config";

const { useSsoDomains, useSsoCapabilities } = vi.hoisted(() => ({
  useSsoDomains: vi.fn(),
  useSsoCapabilities: vi.fn(),
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/sso/hooks", () => ({
  useSsoDomains,
  useSsoCapabilities,
  useCreateSsoDomain: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteSsoDomain: () => ({ mutate: vi.fn(), isPending: false }),
  useUpdateSsoDomainMode: () => ({ mutate: vi.fn(), isPending: false }),
}));

import ManagerSsoPage from "@/app/(enterprise)/manager/sso/page";

const ok = (data: unknown) => ({ isPending: false, isError: false, refetch: vi.fn(), data });

const CAPS = {
  sso_enabled: true,
  oidc: { supported: true, label: "OpenID Connect (OIDC)", providers: [] },
  saml: { supported: false, label: "SAML 2.0", reason: "SAML SSO is not available — use OIDC." },
};

function renderAt(locale: Locale) {
  return render(
    <I18nProvider initialLocale={locale}>
      <Suspense fallback={null}>
        <ManagerSsoPage />
      </Suspense>
    </I18nProvider>,
  );
}

describe("Manager SSO settings i18n smoke", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useSsoDomains.mockReturnValue(ok([]));
    useSsoCapabilities.mockReturnValue(ok(CAPS));
  });

  it("renders in English", () => {
    renderAt("en");
    expect(screen.getByRole("heading", { name: "SSO & email domains" })).toBeInTheDocument();
  });

  it("renders in Arabic", () => {
    renderAt("ar");
    expect(screen.getByRole("heading", { name: "الدخول الموحّد ونطاقات البريد" })).toBeInTheDocument();
  });
});
