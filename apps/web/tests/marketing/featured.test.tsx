import { describe, expect, it, vi } from "vitest";
import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn() }), usePathname: () => "/" }));

import { FeaturedCourses } from "@/components/marketing/featured-courses";
import { ServicePage } from "@/components/marketing/service-page";

describe("Marketing demo content", () => {
  it("renders demo courses and opens a YouTube preview on play", async () => {
    renderWithI18n(<FeaturedCourses />);
    expect(screen.getByText("Project Management Foundations")).toBeInTheDocument();
    expect(screen.getByText("Leadership in the Modern Workplace")).toBeInTheDocument();

    const play = screen.getAllByRole("button", { name: /Play preview/i })[0];
    await userEvent.click(play);
    // The preview iframe mounts on a state update after the click — await it (deterministic, no race).
    const iframe = (await screen.findByTitle("Course preview")) as HTMLIFrameElement;
    expect(iframe.src).toContain("youtube-nocookie.com/embed/");
  });

  it("renders a service page hero + features + highlights", () => {
    renderWithI18n(<ServicePage pageKey="cohorts" />);
    expect(screen.getByText("Mentor-led")).toBeInTheDocument();
    expect(screen.getByText("Peer community")).toBeInTheDocument();
  });
});
