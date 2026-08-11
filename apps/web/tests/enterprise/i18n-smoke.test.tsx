import { describe, expect, it, vi, beforeEach } from "vitest";
import { Suspense } from "react";
import { render, screen } from "@testing-library/react";
import { I18nProvider } from "@/lib/i18n/i18n-context";
import type { Locale } from "@/lib/i18n/config";

const { useSeatSummary, useManagerReport, useDepartments, useTeams } = vi.hoisted(() => ({
  useSeatSummary: vi.fn(),
  useManagerReport: vi.fn(),
  useDepartments: vi.fn(),
  useTeams: vi.fn(),
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/enterprise/manager-hooks", () => ({ useSeatSummary, useManagerReport, useDepartments, useTeams }));

import ManagerDashboardPage from "@/app/(enterprise)/manager/page";

const ok = (data: unknown) => ({ isPending: false, isError: false, refetch: vi.fn(), data });
const paginated = (items: unknown[]) => ok({ data: items, meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 }, links: {} });

const REPORT = {
  organization_id: 1,
  learners: 1,
  enrollments: 1,
  started: 1,
  completions: 1,
  avg_progress: 10,
  watch_time_seconds: 60,
  avg_watch_time_seconds_per_learner: 60,
  inactive_learners: 0,
  assessments_passed: 0,
  assessments_failed: 0,
  certificates_issued: 0,
  seats: { purchased: 10, used: 1, available: 9 },
};

function renderAt(locale: Locale) {
  return render(
    <I18nProvider initialLocale={locale}>
      <Suspense fallback={null}>
        <ManagerDashboardPage />
      </Suspense>
    </I18nProvider>,
  );
}

describe("Manager dashboard i18n smoke", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useDepartments.mockReturnValue(paginated([]));
    useTeams.mockReturnValue(paginated([]));
    useSeatSummary.mockReturnValue(ok({ subscription_id: "s", status: "active", seats: { purchased: 10, used: 1, available: 9 } }));
    useManagerReport.mockReturnValue(ok(REPORT));
  });

  it("renders in English", () => {
    renderAt("en");
    expect(screen.getByRole("heading", { name: "Manager dashboard" })).toBeInTheDocument();
  });

  it("renders in Arabic", () => {
    renderAt("ar");
    expect(screen.getByRole("heading", { name: "لوحة تحكم المدير" })).toBeInTheDocument();
  });
});
