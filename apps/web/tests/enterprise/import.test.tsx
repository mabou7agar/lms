import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useAnalyzeImport, useCommitImport, commitMutate, analyzeReset, commitReset } = vi.hoisted(() => ({
  useAnalyzeImport: vi.fn(),
  useCommitImport: vi.fn(),
  commitMutate: vi.fn(),
  analyzeReset: vi.fn(),
  commitReset: vi.fn(),
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock("@/lib/enterprise/manager-hooks", () => ({ useAnalyzeImport, useCommitImport }));

import ManagerImportPage from "@/app/(enterprise)/manager/import/page";

const analyzeMock = (data: unknown) => ({ mutate: vi.fn(), isPending: false, data, reset: analyzeReset });
const commitMock = (data: unknown = null) => ({ mutate: commitMutate, isPending: false, data, reset: commitReset });

const ERROR_DRY_RUN = {
  summary: { total: 2, valid: 1, errors: 1, duplicates: 0 },
  rows: [
    { line: 2, email: "good@acme.test", name: "Good", role: "member", department_id: null, status: "valid", errors: [] },
    { line: 3, email: "", name: "Bad", role: "member", department_id: null, status: "error", errors: ["Invalid or missing email."] },
  ],
};

const CLEAN_DRY_RUN = {
  summary: { total: 2, valid: 2, errors: 0, duplicates: 0 },
  rows: [
    { line: 2, email: "a@acme.test", name: "A", role: "member", department_id: null, status: "valid", errors: [] },
    { line: 3, email: "b@acme.test", name: "B", role: "member", department_id: null, status: "valid", errors: [] },
  ],
};

describe("ManagerImportPage (CSV dry-run)", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders row errors and blocks commit until they are resolved", () => {
    useAnalyzeImport.mockReturnValue(analyzeMock(ERROR_DRY_RUN));
    useCommitImport.mockReturnValue(commitMock());
    renderWithI18n(<ManagerImportPage />);

    // The row error is surfaced (never dropped silently).
    expect(screen.getByText("Invalid or missing email.")).toBeInTheDocument();
    // Commit is blocked.
    expect(screen.getByText(/Resolve all row errors/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Commit import/i })).toBeDisabled();
  });

  it("enables commit for a clean dry-run and fires the mutation", async () => {
    useAnalyzeImport.mockReturnValue(analyzeMock(CLEAN_DRY_RUN));
    useCommitImport.mockReturnValue(commitMock());
    renderWithI18n(<ManagerImportPage />);

    // A file must be selected for commit to fire.
    await userEvent.upload(
      screen.getByLabelText("CSV file"),
      new File(["email\na@acme.test"], "employees.csv", { type: "text/csv" }),
    );

    const commitBtn = screen.getByRole("button", { name: /Commit import/i });
    expect(commitBtn).toBeEnabled();
    await userEvent.click(commitBtn);
    expect(commitMutate).toHaveBeenCalledWith(expect.objectContaining({ invite: false }), expect.anything());
  });
});
