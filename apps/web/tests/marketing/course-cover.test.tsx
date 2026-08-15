import { afterEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { I18nProvider } from "@/lib/i18n/i18n-context";
import { CourseCover } from "@/components/marketing/course-cover";
import type { CoverCourse, CoverInstructor } from "@/components/marketing/course-cover";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), prefetch: vi.fn() }),
  usePathname: () => "/",
}));

const HREF = "/trainers";
const FOUR: CoverInstructor[] = [
  { name: "Nour Hassan", initials: "NH", key: "copper", href: HREF },
  { name: "Maya Cohen", initials: "MC", key: "indigo", href: HREF },
  { name: "Adam Osei", initials: "AO", key: "teal", href: HREF },
  { name: "Yousef Rahal", initials: "YR", key: "navy", href: HREF },
];

function makeCourse(overrides: Partial<CoverCourse> = {}): CoverCourse {
  return {
    id: "cov_ai_502",
    code: "AIE",
    title: { en: "AI Ethics & Responsible Innovation", ar: "أخلاقيات الذكاء الاصطناعي والابتكار المسؤول" },
    subtitle: { en: "The duty of care", ar: "واجب العناية" },
    family: "ai",
    level: { en: "Graduate · L7", ar: "دراسات عليا · L7" },
    school: { en: "School of Computation", ar: "مدرسة الحوسبة" },
    instructors: FOUR,
    href: "/courses",
    folio: 24,
    ...overrides,
  };
}

function renderCover(
  course: CoverCourse,
  opts: { locale?: "en" | "ar"; wave?: "cradle" | "flow"; onPreview?: (id: string) => void } = {},
) {
  return render(
    <I18nProvider initialLocale={opts.locale ?? "en"}>
      <CourseCover course={course} wave={opts.wave ?? "cradle"} onPreview={opts.onPreview} />
    </I18nProvider>,
  );
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("CourseCover", () => {
  it("renders the title and a course link named by the course", () => {
    renderCover(makeCourse());
    expect(screen.getByRole("heading", { name: "AI Ethics & Responsible Innovation" })).toBeInTheDocument();
    const courseLink = screen.getByRole("link", { name: /AI Ethics & Responsible Innovation/ });
    expect(courseLink).toHaveAttribute("href", "/courses");
    expect(courseLink).toHaveAccessibleName(/Graduate/);
  });

  it("renders each instructor as its own avatar link to the profile", () => {
    const { container } = renderCover(makeCourse());
    const nour = screen.getByRole("link", { name: "Nour Hassan" });
    expect(nour).toHaveAttribute("href", "/trainers");
    // Four instructor avatars + the course link, all distinct anchors (no nested anchors).
    expect(container.querySelectorAll("a.hb-avatar")).toHaveLength(4);
  });

  it("does not leak decorative artwork into the accessibility tree", () => {
    renderCover(makeCourse());
    expect(screen.queryAllByRole("img")).toHaveLength(0);
  });

  it.each([
    ["one", FOUR.slice(0, 1), 1],
    ["two", FOUR.slice(0, 2), 2],
    ["four", FOUR, 4],
  ])("renders %s instructor avatar(s)", (_label, instructors, expected) => {
    const { container } = renderCover(makeCourse({ instructors }));
    expect(container.querySelectorAll("a.hb-avatar")).toHaveLength(expected);
  });

  it("collapses more than four instructors to four avatars plus a +N seal", () => {
    const six: CoverInstructor[] = [
      ...FOUR,
      { name: "Priya Nair", initials: "PN", key: "plum", href: HREF },
      { name: "Dana West", initials: "DW", key: "slate", href: HREF },
    ];
    const { container } = renderCover(makeCourse({ instructors: six }));
    expect(container.querySelectorAll("a.hb-avatar")).toHaveLength(4);
    expect(screen.getByText("+2")).toBeInTheDocument();
  });

  it("exposes a preview control that fires without hiding the course link", async () => {
    const onPreview = vi.fn();
    renderCover(makeCourse(), { onPreview });
    await userEvent.click(screen.getByRole("button", { name: /Play preview/i }));
    expect(onPreview).toHaveBeenCalledWith("cov_ai_502");
    expect(screen.getByRole("link", { name: /AI Ethics/ })).toBeInTheDocument();
  });

  it("renders the flow wave variant", () => {
    const { container } = renderCover(makeCourse(), { wave: "flow" });
    expect(container.querySelector(".hb-cover-flow")).not.toBeNull();
  });

  it("renders under prefers-reduced-motion without throwing", () => {
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
  });
});
