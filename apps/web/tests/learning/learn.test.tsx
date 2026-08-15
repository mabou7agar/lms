import { describe, expect, it, vi } from "vitest";
import { screen } from "@testing-library/react";
import { renderWithI18n } from "../render";

vi.mock("next/navigation", () => ({
  useParams: () => ({ public_id: "crs1" }),
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
  usePathname: () => "/learn",
  useSearchParams: () => new URLSearchParams(),
}));
vi.mock("@/lib/auth/auth-context", () => ({ useAuth: () => ({ status: "authenticated", user: { id: "u1" } }) }));
// The learner player route now mounts the consolidated CoursePlayerShell. The shell's own behavior
// (curriculum, resume, progress, locking, completion, RTL) is covered by the player-* tests, so it is
// stubbed here; this test only asserts the route wires the shell with the routed course public_id.
vi.mock("@/components/learning/player", () => ({
  CoursePlayerShell: ({ courseId }: { courseId: string }) => <div data-testid="player-shell">{courseId}</div>,
}));
vi.mock("@/components/community/course-community-panel", () => ({ CourseCommunityPanel: () => null }));

import CourseLearnPage from "@/app/(learning)/(player)/learn/[public_id]/page";

describe("CourseLearnPage", () => {
  it("mounts the consolidated course player shell for the routed course", () => {
    renderWithI18n(<CourseLearnPage />);
    expect(screen.getByTestId("player-shell")).toHaveTextContent("crs1");
  });
});
