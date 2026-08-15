import { describe, expect, it } from "vitest";
import { normalizeLessonContent } from "@/lib/learning/player-api";

describe("normalizeLessonContent", () => {
  it("normalizes legacy lesson payloads with array content into an empty block list", () => {
    const result = normalizeLessonContent(
      {
        id: "lesson-1",
        title: "Welcome",
        type: "video",
        content: [],
        progress: { position_seconds: 180 },
      },
      "fallback",
    );

    expect(result).toMatchObject({
      id: "lesson-1",
      title: "Welcome",
      type: "video",
      blocks: [],
      video: { position_seconds: 180, duration_seconds: null },
    });
  });

  it("keeps block payloads already nested under content.blocks", () => {
    const result = normalizeLessonContent({
      id: "lesson-2",
      title: "Article",
      type: "text",
      content: { blocks: [{ id: "b1", kind: "text", body: "Body" }] },
    });

    expect(result.blocks).toEqual([{ id: "b1", kind: "text", body: "Body" }]);
  });
});
