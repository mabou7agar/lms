import { describe, expect, it, vi, beforeEach } from "vitest";
import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { renderWithI18n } from "../render";
import { normalizeLessonContent } from "@/lib/learning/player-api";

/*
 | An `external_link` lesson (a Vimeo module) carries its target on `content.url` and defines NO
 | blocks. The canonical player only ever rendered `blocks`, so those lessons appeared as an empty
 | lesson body, and /lessons offered nothing but an "Open link" button that took the learner off the
 | page. Resources had the mirror problem: listed on /lessons, absent from the canonical player.
 */

const VIMEO = "https://player.vimeo.com/video/76979871";

const { downloadMutate, useCourseResources, useLessonResources, toastError, toastSuccess } = vi.hoisted(() => ({
  downloadMutate: vi.fn(),
  useCourseResources: vi.fn(),
  useLessonResources: vi.fn(),
  toastError: vi.fn(),
  toastSuccess: vi.fn(),
}));

vi.mock("@/lib/courseware/hooks", () => ({
  useCourseResources,
  useLessonResources,
  useDownloadResource: () => ({ mutate: downloadMutate, isPending: false, variables: undefined }),
}));
vi.mock("@/components/ui/toast", () => ({
  toast: { error: toastError, success: toastSuccess },
}));

import { ResourceList, openSignedUrl } from "@/components/courseware/resource-list";
import { LessonContent } from "@/components/learning/lesson-content";

const resource = {
  id: "res_1",
  title: "Module 1 worksheet",
  description: "Exercises for module 1.",
  is_preview: false,
  downloadable: true,
  file: { mime_type: "application/pdf", size_bytes: 2048 },
};
const ok = (data: unknown) => ({ isPending: false, isError: false, refetch: vi.fn(), data });

beforeEach(() => {
  vi.clearAllMocks();
  useCourseResources.mockReturnValue(ok({ items: [resource], entitled: true }));
  useLessonResources.mockReturnValue(ok({ items: [resource], entitled: true }));
});

describe("external_link lessons", () => {
  const lesson = (url: string) => ({
    id: "l1",
    title: "Module 1",
    type: "external_link" as const,
    content: { url },
    playback: null,
    assessment: null,
  });

  it("plays a Vimeo lesson inline instead of only linking out", () => {
    renderWithI18n(<LessonContent lesson={lesson(VIMEO) as any} />);

    const frame = document.querySelector("iframe");
    expect(frame).not.toBeNull();
    expect(frame?.getAttribute("src")).toContain("player.vimeo.com/video/76979871");

    // The raw link survives as a secondary escape hatch.
    expect(screen.getByRole("link", { name: /open link/i })).toHaveAttribute("href", VIMEO);
  });

  it("falls back to a plain link when the URL is not embeddable", () => {
    renderWithI18n(<LessonContent lesson={lesson("mailto:someone@example.com") as any} />);

    expect(document.querySelector("iframe")).toBeNull();
  });

  it("carries content.url through normalization so the canonical player can render it", () => {
    const normalized = normalizeLessonContent(
      { id: "l1", title: "Module 1", type: "external_link", content: { url: VIMEO } } as any,
    );

    expect(normalized.externalUrl).toBe(VIMEO);
    expect(normalized.blocks).toEqual([]);
  });
});

describe("resource list", () => {
  it("lists lesson resources", () => {
    renderWithI18n(<ResourceList lessonId="l1" />);
    expect(screen.getByText("Module 1 worksheet")).toBeInTheDocument();
  });

  it("lists course resources", () => {
    renderWithI18n(<ResourceList courseId="c1" />);
    expect(useCourseResources).toHaveBeenCalled();
    expect(screen.getByText("Module 1 worksheet")).toBeInTheDocument();
  });

  it("reports a download the browser refused instead of doing nothing", async () => {
    // The signed URL arrives after the round trip, so the click gesture is gone and the popup is
    // blocked. Both window.open and the anchor fallback are refused here.
    vi.spyOn(window, "open").mockReturnValue(null);
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => {
      throw new Error("blocked");
    });
    downloadMutate.mockImplementation((_id, opts) => opts.onSuccess({ url: "https://signed.example/f.pdf" }));

    renderWithI18n(<ResourceList lessonId="l1" />);
    await userEvent.click(screen.getByRole("button", { name: /download/i }));

    await waitFor(() => expect(toastError).toHaveBeenCalled());
    expect(toastSuccess).not.toHaveBeenCalled();
    clickSpy.mockRestore();
  });

  it("confirms a download that opened", async () => {
    vi.spyOn(window, "open").mockReturnValue({} as Window);
    downloadMutate.mockImplementation((_id, opts) => opts.onSuccess({ url: "https://signed.example/f.pdf" }));

    renderWithI18n(<ResourceList lessonId="l1" />);
    await userEvent.click(screen.getByRole("button", { name: /download/i }));

    await waitFor(() => expect(toastSuccess).toHaveBeenCalled());
    expect(toastError).not.toHaveBeenCalled();
  });

  it("surfaces a server refusal", async () => {
    downloadMutate.mockImplementation((_id, opts) => opts.onError(new Error("nope")));

    renderWithI18n(<ResourceList lessonId="l1" />);
    await userEvent.click(screen.getByRole("button", { name: /download/i }));

    await waitFor(() => expect(toastError).toHaveBeenCalled());
  });

  it("uses the anchor fallback when the popup blocker refuses window.open", () => {
    vi.spyOn(window, "open").mockReturnValue(null);
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => {});

    expect(openSignedUrl("https://signed.example/f.pdf")).toBe(true);
    expect(clickSpy).toHaveBeenCalled();
    clickSpy.mockRestore();
  });
});
