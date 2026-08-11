import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

const { useNotifications, useUpdatePreferences, useMarkNotificationRead, useAuth, updateMutate } = vi.hoisted(() => ({
  useNotifications: vi.fn(),
  useUpdatePreferences: vi.fn(),
  useMarkNotificationRead: vi.fn(),
  useAuth: vi.fn(),
  updateMutate: vi.fn(),
}));

vi.mock("@/lib/student/hooks", () => ({ useNotifications, useUpdatePreferences, useMarkNotificationRead }));
vi.mock("@/lib/auth/auth-context", () => ({ useAuth }));

import NotificationsPage from "@/app/(account)/notifications/page";

const ok = (data: unknown) => ({ isPending: false, isError: false, refetch: vi.fn(), data });

describe("Notification preferences — quiet hours", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useNotifications.mockReturnValue(ok({ data: [], meta: { current_page: 1, last_page: 1 }, links: {} }));
    useUpdatePreferences.mockReturnValue({ mutate: updateMutate, isPending: false });
    useMarkNotificationRead.mockReturnValue({ mutate: vi.fn(), isPending: false, variables: undefined });
    useAuth.mockReturnValue({ user: { locale: "en" } });
  });

  it("renders the quiet-hours controls and the transactional-still-sends note", () => {
    renderWithI18n(<NotificationsPage />);
    expect(screen.getByText("Quiet hours")).toBeInTheDocument();
    expect(screen.getByRole("switch")).toBeInTheDocument();
    expect(screen.getByLabelText("Start time")).toBeInTheDocument();
    expect(screen.getByLabelText("End time")).toBeInTheDocument();
    // The explanation that critical/transactional messages are never suppressed.
    expect(screen.getByText(/always delivered/i)).toBeInTheDocument();
  });

  it("persists the quiet-hours window through the mocked save flow when enabled", async () => {
    const user = userEvent.setup();
    renderWithI18n(<NotificationsPage />);

    await user.click(screen.getByRole("switch"));
    await user.click(screen.getByRole("button", { name: "Save preferences" }));

    await waitFor(() =>
      expect(updateMutate).toHaveBeenCalledWith(
        expect.objectContaining({
          quiet_hours_enabled: true,
          quiet_hours_start: "22:00",
          quiet_hours_end: "07:00",
        }),
        expect.anything(),
      ),
    );
  });
});
