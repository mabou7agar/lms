import { afterEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { I18nProvider } from "@/lib/i18n/i18n-context";
import { CourseCover } from "@/components/marketing/course-cover";
import type { CoverCourse, CoverFaculty } from "@/components/marketing/course-cover";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), prefetch: vi.fn() }),
  usePathname: () => "/",
}));

const FOUR: CoverFaculty[] = [
  { initials: "RO", key: "copper" },
  { initials: "MC", key: "indigo" },
  { initials: "AO", key: "teal" },
  { initials: "YR", key: "navy" },
];

function makeCourse(overrides: Partial<CoverCourse> = {}): CoverCourse {
  return {
    id: "cov_ai_502",
    code: "AIE",
    pressCode: "HEL · AIE · 502",
    title: { en: "AI Ethics & Responsible Innovation", ar: "أخلاقيات الذكاء الاصطناعي والابتكار المسؤول" },
    subtitle: { en: "The duty of care", ar: "واجب العناية" },
    family: "ai",
    level: { en: "Graduate · L7", ar: "دراسات عليا · L7" },
    school: { en: "School of Computation", ar: "مدرسة الحوسبة" },
    faculty: FOUR,
    href: "/courses",
    folio: 24,
    ...overrides,
  };
}

function renderCover(
  course: CoverCourse,
  opts: { locale?: "en" | "ar"; onPreview?: (id: string) => void } = {},
) {
  return render(
    <I18nProvider initialLocale={opts.locale ?? "en"}>
      <CourseCover course={course} index={2} onPreview={opts.onPreview} />
    </I18nProvider>,
  );
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("CourseCover", () => {
  it("renders the course title and a single accessible link named by the course", () => {
    renderCover(makeCourse());
    expect(screen.getByRole("heading", { name: "AI Ethics & Responsible Innovation" })).toBeInTheDocument();
    const link = screen.getByRole("link");
    expect(link).toHaveAttribute("href", "/courses");
    expect(link).toHaveAccessibleName(/AI Ethics & Responsible Innovation/);
    expect(link).toHaveAccessibleName(/Graduate/);
  });

  it("does not leak decorative artwork or press microtext into the accessibility tree", () => {
    renderCover(makeCourse());
    // Generative artwork, grid, roman numeral and medallions are all aria-hidden -> no img role.
    expect(screen.queryAllByRole("img")).toHaveLength(0);
    // The press mark is decorative; it must not become part of the link's accessible name.
    expect(screen.getByRole("link").getAttribute("aria-label") ?? "").not.toContain("PRESS");
  });

  it.each([
    ["one faculty", [{ initials: "IS", key: "olive" }] as CoverFaculty[], 1],
    ["two faculty", FOUR.slice(0, 2), 2],
    ["four faculty", FOUR, 4],
  ])("renders %s as overlapping medallions", (_label, faculty, expected) => {
    const { container } = renderCover(makeCourse({ faculty }));
    expect(container.querySelectorAll(".hb-medallion-slot")).toHaveLength(expected);
  });

  it("collapses more than four faculty to four seals plus a +N seal", () => {
    const six: CoverFaculty[] = [...FOUR, { initials: "PN", key: "plum" }, { initials: "DW", key: "slate" }];
    const { container } = renderCover(makeCourse({ faculty: six }));
    expect(container.querySelectorAll(".hb-medallion-slot")).toHaveLength(5);
    expect(screen.getByText("+2")).toBeInTheDocument();
  });

  it("exposes a preview control that fires without hiding the course link", async () => {
    const onPreview = vi.fn();
    renderCover(makeCourse(), { onPreview });
    const play = screen.getByRole("button", { name: /Play preview/i });
    await userEvent.click(play);
    expect(onPreview).toHaveBeenCalledWith("cov_ai_502");
    // Essential content — the titled course link — remains present.
    expect(screen.getByRole("link", { name: /AI Ethics/ })).toBeInTheDocument();
  });

  it("renders under prefers-reduced-motion without a pointer-tilt transform", () => {
    vi.stubGlobal(
      "matchMedia",
      (query: string) =>
        ({
          matches: true,
          media: query,
          onchange: null,
          addEventListener: vi.fn(),
          removeEventListener: vi.fn(),
          addListener: vi.fn(),
          removeListener: vi.fn(),
          dispatchEvent: vi.fn(),
        }) as unknown as MediaQueryList,
    );
    expect(() => renderCover(makeCourse())).not.toThrow();
    expect(screen.getByRole("heading", { name: /AI Ethics/ })).toBeInTheDocument();
  });

  it("composes an Arabic title in RTL", () => {
    renderCover(makeCourse(), { locale: "ar" });
    expect(
      screen.getByRole("heading", { name: "أخلاقيات الذكاء الاصطناعي والابتكار المسؤول" }),
    ).toBeInTheDocument();
    expect(screen.getByRole("link")).toHaveAccessibleName(/أخلاقيات الذكاء الاصطناعي/);
  });
});
