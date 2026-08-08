import { afterEach, describe, expect, it, vi } from "vitest";
import {
  StaleWriteError,
  UnsupportedBlockError,
  createContentBlock,
  deleteContentBlock,
  duplicateContentBlock,
  listContentBlocks,
  reorderContentBlocks,
  setContentBlockPublish,
  updateContentBlock,
} from "@/lib/authoring/api";

/**
 * C5 — nested content-blocks API client. Same contract as the section/lesson layer:
 *  • create/update carry the typed `content_i18n` {en,ar} map (never a raw JSON blob);
 *  • reorder is server-authoritative and threads the parent lesson's `expected_version`, returning
 *    the lesson's advanced `lock_version`;
 *  • optimistic-concurrency — the 409 `stale_write` body becomes a typed StaleWriteError;
 *  • only backend-supported kinds are ever sent.
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

function sentBody(fn: ReturnType<typeof vi.fn>): Record<string, unknown> {
  const init = fn.mock.calls.at(-1)?.[1] as RequestInit;
  return JSON.parse(init.body as string) as Record<string, unknown>;
}

const rawBlock = {
  id: "b1",
  type: "article",
  family: "content",
  position: 0,
  publish_state: "draft",
  lock_version: 1,
  content: { html: "<p>hi</p>" },
  content_i18n: { en: { html: "<p>hi</p>" }, ar: { html: "<p>مرحبا</p>" } },
  config: null,
  learning_object_id: null,
};

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("list", () => {
  it("maps blocks (both languages + lock_version) ordered from the server", async () => {
    mockFetch(200, { data: [rawBlock] });
    const blocks = await listContentBlocks("l1");
    expect(blocks).toHaveLength(1);
    expect(blocks[0].content_i18n).toEqual({ en: { html: "<p>hi</p>" }, ar: { html: "<p>مرحبا</p>" } });
    expect(blocks[0].lock_version).toBe(1);
  });
});

describe("create / edit send the typed content_i18n map", () => {
  it("createContentBlock POSTs the type and the {en,ar} content map", async () => {
    const fn = mockFetch(201, { data: rawBlock });
    await createContentBlock("l1", { type: "article", content_i18n: { en: { html: "<p>hi</p>" }, ar: { html: "<p>مرحبا</p>" } } });

    expect(fn.mock.calls[0][0]).toBe("/api/backend/admin/lessons/l1/blocks");
    expect((fn.mock.calls[0][1] as RequestInit).method).toBe("POST");
    const body = sentBody(fn);
    expect(body.type).toBe("article");
    expect(body.content_i18n).toEqual({ en: { html: "<p>hi</p>" }, ar: { html: "<p>مرحبا</p>" } });
  });

  it("updateContentBlock PUTs the content map plus expected_version", async () => {
    const fn = mockFetch(200, { data: rawBlock });
    await updateContentBlock("b1", { content_i18n: { en: { html: "<p>x</p>" }, ar: {} }, expected_version: 1 });

    expect(fn.mock.calls[0][0]).toBe("/api/backend/admin/blocks/b1");
    expect((fn.mock.calls[0][1] as RequestInit).method).toBe("PUT");
    const body = sentBody(fn);
    expect(body.content_i18n).toEqual({ en: { html: "<p>x</p>" }, ar: {} });
    expect(body.expected_version).toBe(1);
  });

  it("refuses to send an unsupported kind (never faked)", async () => {
    mockFetch(200, { data: rawBlock });
    await expect(createContentBlock("l1", { type: "scorm" })).rejects.toBeInstanceOf(UnsupportedBlockError);
  });
});

describe("reorder is server-authoritative and optimistic-locked", () => {
  it("sends the order + expected_version and returns the lesson's advanced lock_version", async () => {
    const fn = mockFetch(200, { data: { lock_version: 7 } });
    const result = await reorderContentBlocks("l1", ["b3", "b1", "b2"], 6);

    expect(fn.mock.calls[0][0]).toBe("/api/backend/admin/lessons/l1/blocks/order");
    expect((fn.mock.calls[0][1] as RequestInit).method).toBe("PUT");
    const body = sentBody(fn);
    expect(body.order).toEqual(["b3", "b1", "b2"]);
    expect(body.expected_version).toBe(6);
    expect(result.lock_version).toBe(7);
  });
});

describe("duplicate calls the backend deep-copy endpoint", () => {
  it("POSTs to the block duplicate route under its lesson", async () => {
    const fn = mockFetch(201, { data: { ...rawBlock, id: "b2", position: 1 } });
    const created = await duplicateContentBlock("l1", "b1");

    expect(fn.mock.calls[0][0]).toBe("/api/backend/admin/lessons/l1/blocks/b1/duplicate");
    expect((fn.mock.calls[0][1] as RequestInit).method).toBe("POST");
    expect(created.id).toBe("b2");
  });
});

describe("publish + delete", () => {
  it("setContentBlockPublish POSTs the target state", async () => {
    const fn = mockFetch(200, { data: { ...rawBlock, publish_state: "published" } });
    const updated = await setContentBlockPublish("b1", "published");
    expect(fn.mock.calls[0][0]).toBe("/api/backend/admin/blocks/b1/publish");
    expect(sentBody(fn).state).toBe("published");
    expect(updated.publish_state).toBe("published");
  });

  it("deleteContentBlock DELETEs the block", async () => {
    const fn = mockFetch(204, {});
    await deleteContentBlock("b1");
    expect(fn.mock.calls[0][0]).toBe("/api/backend/admin/blocks/b1");
    expect((fn.mock.calls[0][1] as RequestInit).method).toBe("DELETE");
  });
});

describe("optimistic-concurrency 409", () => {
  it("turns the stale_write body into a typed StaleWriteError carrying current_version", async () => {
    mockFetch(409, { error: "stale_write", current_version: 9 });
    try {
      await updateContentBlock("b1", { content_i18n: { en: { html: "<p>x</p>" } }, expected_version: 1 });
      throw new Error("should have thrown");
    } catch (e) {
      expect(e).toBeInstanceOf(StaleWriteError);
      expect((e as StaleWriteError).currentVersion).toBe(9);
    }
  });

  it("also raises StaleWriteError from a reorder conflict", async () => {
    mockFetch(409, { error: "stale_write", current_version: 4 });
    await expect(reorderContentBlocks("l1", ["b1", "b2"], 2)).rejects.toBeInstanceOf(StaleWriteError);
  });
});
