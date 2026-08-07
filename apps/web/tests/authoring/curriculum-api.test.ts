import { afterEach, describe, expect, it, vi } from "vitest";
import {
  StaleWriteError,
  duplicateBlock,
  duplicateSection,
  getCurriculum,
  updateBlock,
  updateSection,
} from "@/lib/authoring/api";
import { resolveLocalized } from "@/lib/authoring/authoring-i18n";

/**
 * The Course Builder API client. These cover the three curriculum defects:
 *  • C1 bilingual EN/AR — reads/writes the `*_i18n` maps, dual-writes the legacy scalar, never
 *    leaks the map into the learner-facing `content`.
 *  • C2 duplication — hits the backend deep-copy endpoints (no client re-creation).
 *  • C3 concurrency — sends `expected_version`, and turns the 409 stale-write body into a typed error.
 */

function mockFetch(status: number, body: unknown) {
  const fn = vi.fn().mockResolvedValue({
    ok: status >= 200 && status < 300,
    status,
    statusText: "",
    json: async () => body,
  });
  vi.stubGlobal("fetch", fn);
  return fn;
}

/** The JSON body sent with the most recent request. */
function sentBody(fn: ReturnType<typeof vi.fn>): Record<string, unknown> {
  const init = fn.mock.calls.at(-1)?.[1] as RequestInit;
  return JSON.parse(init.body as string) as Record<string, unknown>;
}

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("C1 — bilingual read mapping", () => {
  it("maps both languages from the server's *_i18n maps on reload", async () => {
    mockFetch(200, {
      data: {
        sections: [
          {
            id: "s1",
            title: "Intro",
            title_i18n: { en: "Intro", ar: "مقدمة" },
            summary: "About",
            summary_i18n: { en: "About", ar: "حول" },
            position: 0,
            publish_state: "draft",
            lock_version: 4,
            lessons: [
              {
                id: "l1",
                title: "Welcome",
                title_i18n: { en: "Welcome", ar: "أهلا" },
                type: "article",
                content: { html: "<p>hi</p>" },
                position: 0,
                publish_state: "draft",
                lock_version: 2,
                is_preview: false,
                media: null,
              },
            ],
          },
        ],
      },
    });

    const curriculum = await getCurriculum("c1");
    const section = curriculum.sections[0];
    const lesson = section.blocks[0];

    expect(section.title_i18n).toEqual({ en: "Intro", ar: "مقدمة" });
    expect(section.summary_i18n).toEqual({ en: "About", ar: "حول" });
    expect(lesson.title_i18n).toEqual({ en: "Welcome", ar: "أهلا" });
    // Concurrency tokens are threaded through the read.
    expect(section.lock_version).toBe(4);
    expect(lesson.lock_version).toBe(2);
  });

  it("seeds English from the scalar when the server only emits the localized string", async () => {
    mockFetch(200, {
      data: {
        sections: [
          {
            id: "s1",
            title: "Legacy",
            summary: null,
            position: 0,
            publish_state: "draft",
            lessons: [],
          },
        ],
      },
    });

    const section = (await getCurriculum("c1")).sections[0];
    expect(section.title_i18n).toEqual({ en: "Legacy", ar: "" });
    expect(section.summary_i18n).toEqual({ en: "", ar: "" });
  });

  it("AR falls back to EN for display when Arabic is empty", () => {
    expect(resolveLocalized({ en: "Hello", ar: "" }, "ar")).toBe("Hello");
    expect(resolveLocalized({ en: "Hello", ar: "مرحبا" }, "ar")).toBe("مرحبا");
    expect(resolveLocalized({ en: "Hello", ar: "مرحبا" }, "en")).toBe("Hello");
  });
});

describe("C1 — bilingual write payload (dual-write)", () => {
  it("updateSection sends the i18n map AND the legacy English scalar plus expected_version", async () => {
    const fn = mockFetch(200, {
      data: { id: "s1", title: "Intro", title_i18n: { en: "Intro", ar: "مقدمة" }, summary: null, position: 0, publish_state: "draft", lock_version: 5, lessons: [] },
    });

    await updateSection("s1", { title_i18n: { en: "Intro", ar: "مقدمة" }, expected_version: 4 });

    const body = sentBody(fn);
    expect(body.title).toBe("Intro"); // legacy scalar = English
    expect(body.title_i18n).toEqual({ en: "Intro", ar: "مقدمة" });
    expect(body.expected_version).toBe(4);
    expect(fn.mock.calls[0][0]).toBe("/api/backend/admin/sections/s1");
  });

  it("updateBlock dual-writes the title and never puts the map inside learner content", async () => {
    const fn = mockFetch(200, {
      data: { id: "l1", title: "Lesson", title_i18n: { en: "Lesson", ar: "درس" }, type: "article", content: { html: "<p>x</p>" }, position: 0, publish_state: "draft", lock_version: 3, is_preview: false, media: null },
    });

    await updateBlock("l1", { title_i18n: { en: "Lesson", ar: "درس" }, expected_version: 2 });

    const body = sentBody(fn);
    expect(body.title).toBe("Lesson");
    expect(body.title_i18n).toEqual({ en: "Lesson", ar: "درس" });
    // The translation map is a top-level field, never smuggled into `content`.
    expect(body).not.toHaveProperty("content");
  });
});

describe("C1 — no translation-map leak into learner-facing content", () => {
  it("keeps title_i18n out of the lesson content payload the learner renderer reads", async () => {
    mockFetch(200, {
      data: {
        sections: [
          {
            id: "s1",
            title: "S",
            title_i18n: { en: "S", ar: "س" },
            summary: null,
            position: 0,
            publish_state: "draft",
            lessons: [
              { id: "l1", title: "L", title_i18n: { en: "L", ar: "ل" }, type: "article", content: { html: "<p>only html</p>" }, position: 0, publish_state: "draft", lock_version: 1, is_preview: false, media: null },
            ],
          },
        ],
      },
    });

    const lesson = (await getCurriculum("c1")).sections[0].blocks[0];
    // The map lives on the block, not in the content bag the player renders.
    expect(lesson.title_i18n).toEqual({ en: "L", ar: "ل" });
    expect(lesson.content).toEqual({ html: "<p>only html</p>" });
    expect(Object.keys(lesson.content).some((k) => k.includes("i18n"))).toBe(false);
  });
});

describe("C2 — duplication calls the backend deep-copy endpoints", () => {
  it("duplicateSection POSTs to the section duplicate route and maps the clone (both languages)", async () => {
    const fn = mockFetch(201, {
      data: {
        id: "s2",
        title: "Intro (copy)",
        title_i18n: { en: "Intro (copy)", ar: "مقدمة (نسخة)" },
        summary: null,
        position: 1,
        publish_state: "draft",
        lock_version: 0,
        lessons: [
          { id: "l9", title: "Kept", title_i18n: { en: "Kept", ar: "محفوظ" }, type: "video", content: {}, position: 0, publish_state: "draft", lock_version: 0, is_preview: false, media: { mux_asset_id: "a", mux_playback_id: "p", s3_key: null, mime_type: null, duration: 10, filesize: 1 }, prerequisites: [{ id: "l1", title: "Pre" }] },
        ],
      },
    });

    const created = await duplicateSection("c1", "s1");

    expect(fn.mock.calls[0][0]).toBe("/api/backend/admin/courses/c1/sections/s1/duplicate");
    expect((fn.mock.calls[0][1] as RequestInit).method).toBe("POST");
    // The server clone preserves media, prerequisites and both languages.
    expect(created.title_i18n).toEqual({ en: "Intro (copy)", ar: "مقدمة (نسخة)" });
    expect(created.blocks[0].title_i18n).toEqual({ en: "Kept", ar: "محفوظ" });
    expect(created.blocks[0].media?.mux_playback_id).toBe("p");
    expect(created.blocks[0].prerequisites).toHaveLength(1);
  });

  it("duplicateBlock POSTs to the lesson duplicate route under its section", async () => {
    const fn = mockFetch(201, {
      data: { id: "l2", title: "Copy", title_i18n: { en: "Copy", ar: "نسخة" }, type: "article", content: {}, position: 1, publish_state: "draft", lock_version: 0, is_preview: false, media: null },
    });

    const created = await duplicateBlock("s1", "l1");

    expect(fn.mock.calls[0][0]).toBe("/api/backend/admin/sections/s1/lessons/l1/duplicate");
    expect((fn.mock.calls[0][1] as RequestInit).method).toBe("POST");
    expect(created.title_i18n).toEqual({ en: "Copy", ar: "نسخة" });
  });
});

describe("C3 — optimistic-concurrency 409 handling", () => {
  it("turns the stale_write 409 body into a typed StaleWriteError with the server's current_version", async () => {
    mockFetch(409, { error: "stale_write", current_version: 9 });

    await expect(updateSection("s1", { title_i18n: { en: "a", ar: "" }, expected_version: 3 })).rejects.toBeInstanceOf(
      StaleWriteError,
    );

    mockFetch(409, { error: "stale_write", current_version: 9 });
    try {
      await updateBlock("l1", { title_i18n: { en: "a", ar: "" }, expected_version: 3 });
      throw new Error("should have thrown");
    } catch (e) {
      expect(e).toBeInstanceOf(StaleWriteError);
      expect((e as StaleWriteError).currentVersion).toBe(9);
    }
  });
});
