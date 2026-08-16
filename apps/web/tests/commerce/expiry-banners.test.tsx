import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";
import { daysUntil, hasExpired, isExpiringSoon } from "@/lib/commerce/expiry";

const { useMyLearning, useMyCertificates, useEntitlements } = vi.hoisted(() => ({
  useMyLearning: vi.fn(),
  useMyCertificates: vi.fn(),
  useEntitlements: vi.fn(),
}));

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/student/hooks", () => ({
  useMyLearning,
  useMyCertificates,
  useCertificateDownload: () => ({ mutate: vi.fn(), isPending: false, variables: undefined }),
  useCertificateShare: () => ({ mutate: vi.fn(), isPending: false, variables: undefined }),
}));
vi.mock("@/lib/enterprise/manager-hooks", () => ({
  useEntitlements,
  useEntitlement: () => ({ isPending: false, isError: false, data: undefined }),
  useAssignEntitlement: () => ({ mutate: vi.fn(), isPending: false }),
  useRevokeEntitlement: () => ({ mutate: vi.fn(), isPending: false }),
  useMembers: () => ({ isPending: false, isError: false, data: { data: [], meta: { total: 0 } } }),
  useDepartments: () => ({ isPending: false, isError: false, data: { data: [], meta: { total: 0 } } }),
  useTeams: () => ({ isPending: false, isError: false, data: { data: [], meta: { total: 0 } } }),
}));

import MyLearningPage from "@/app/(learning)/(app)/my-learning/page";
import CertificatesPage from "@/app/(learning)/(app)/certificates/page";
import { PurchasedTraining } from "@/components/enterprise/purchased-training";

const ok = (data: unknown) => ({ isPending: false, isError: false, refetch: vi.fn(), data });
const inDays = (n: number) => new Date(Date.now() + n * 86_400_000).toISOString();

function learningItem(overrides: Record<string, unknown> = {}) {
  return {
    enrollment_id: "en_1",
    status: "active",
    progress_percentage: 10,
    enrolled_at: null,
    completed_at: null,
    course: { id: "c1", title: "Leading Teams", slug: "leading-teams", thumbnail_path: null },
    source: "company_seat",
    company_granted: true,
    expires_at: null,
    expired: false,
    ...overrides,
  };
}

function certificate(overrides: Record<string, unknown> = {}) {
  return {
    id: "cert_1",
    number: "HB-2026-0001",
    status: "issued",
    course_title: "Leading Teams",
    issued_at: inDays(-10),
    expires_at: null,
    expired: false,
    company_name: null,
    company_branded: false,
    ...overrides,
  };
}

describe("expiry helpers", () => {
  it("counts whole days to a future date and reports a past one as expired", () => {
    expect(daysUntil(inDays(5))).toBe(5);
    expect(hasExpired(inDays(-1))).toBe(true);
    expect(hasExpired(inDays(1))).toBe(false);
  });

  it("treats a missing date as nothing to warn about", () => {
    expect(daysUntil(null)).toBeNull();
    expect(hasExpired(null)).toBe(false);
    expect(isExpiringSoon(null)).toBe(false);
  });

  it("only calls something expiring soon while it is still valid and inside the window", () => {
    expect(isExpiringSoon(inDays(5))).toBe(true);
    expect(isExpiringSoon(inDays(120))).toBe(false);
    // Already gone is NOT "expiring soon" — that is a different banner with a different message.
    expect(isExpiringSoon(inDays(-1))).toBe(false);
  });
});

describe("My Learning expiry banners", () => {
  beforeEach(() => vi.clearAllMocks());

  it("warns when company-granted access is about to end", () => {
    useMyLearning.mockReturnValue(ok([learningItem({ expires_at: inDays(9) })]));

    renderWithI18n(<MyLearningPage />);

    expect(screen.getByText(/Access to 1 course\(s\) ends soon/i)).toBeInTheDocument();
  });

  it("says nothing when access has no end date", () => {
    useMyLearning.mockReturnValue(ok([learningItem()]));

    renderWithI18n(<MyLearningPage />);

    expect(screen.queryByText(/ends soon/i)).not.toBeInTheDocument();
  });

  it("reports access that has already ended separately", () => {
    useMyLearning.mockReturnValue(ok([learningItem({ expires_at: inDays(-2), expired: true })]));

    renderWithI18n(<MyLearningPage />);

    expect(screen.getByText(/Access to 1 course\(s\) has ended/i)).toBeInTheDocument();
  });
});

describe("Certificates page", () => {
  beforeEach(() => vi.clearAllMocks());

  it("warns about a credential approaching the end of its validity", () => {
    useMyCertificates.mockReturnValue(ok([certificate({ expires_at: inDays(12) })]));

    renderWithI18n(<CertificatesPage />);

    expect(screen.getByText(/1 certificate\(s\) expire soon/i)).toBeInTheDocument();
    expect(screen.getByText(/Valid until/i)).toBeInTheDocument();
  });

  it("shows a lapsed credential as expired rather than hiding it", () => {
    useMyCertificates.mockReturnValue(ok([certificate({ status: "expired", expires_at: inDays(-5), expired: true })]));

    renderWithI18n(<CertificatesPage />);

    expect(screen.getByText("Expired")).toBeInTheDocument();
    expect(screen.getByText(/Expired on/i)).toBeInTheDocument();
    // Still listed: a lapsed certificate is proof of what was completed.
    expect(screen.getByText("Leading Teams")).toBeInTheDocument();
  });

  it("marks a company-branded credential with the company that earned it", () => {
    useMyCertificates.mockReturnValue(
      ok([certificate({ company_name: "Northwind Trading", company_branded: true })]),
    );

    renderWithI18n(<CertificatesPage />);

    expect(screen.getByText("Northwind Trading")).toBeInTheDocument();
  });
});

describe("Manager purchased-training banner", () => {
  beforeEach(() => vi.clearAllMocks());

  const entitlement = (overrides: Record<string, unknown> = {}) => ({
    id: "ent_1",
    product_title: "Compliance Program",
    order_id: "ord_1",
    courses: [{ id: "c1", title: "Leading Teams" }],
    seats: { purchased: 5, used: 3, available: 2, unlimited: false },
    status: "active",
    assignable: true,
    access_starts_at: null,
    access_ends_at: null,
    policy: {
      seat_mode: "fixed",
      reassignment: "always",
      reassignment_progress_threshold: null,
      certificate_branding: "helbaron_only",
      employee_access_expires_with_purchase: true,
    },
    ...overrides,
  });

  it("tells the manager how many employees a lapsing purchase will affect", () => {
    useEntitlements.mockReturnValue(ok([entitlement({ access_ends_at: inDays(10) })]));

    renderWithI18n(<PurchasedTraining />);

    expect(screen.getByText(/1 purchase\(s\) expire soon — 3 employee\(s\) will lose access/i)).toBeInTheDocument();
  });

  it("stays quiet for a purchase with plenty of time left", () => {
    useEntitlements.mockReturnValue(ok([entitlement({ access_ends_at: inDays(200) })]));

    renderWithI18n(<PurchasedTraining />);

    expect(screen.queryByText(/expire soon/i)).not.toBeInTheDocument();
  });
});
