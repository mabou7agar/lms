import { afterEach, describe, expect, it, vi } from "vitest";
import { apiFetch, ApiRequestError, hasSession, sessionLogin, sessionLogout } from "@/lib/api/client";

function mockFetch(status: number, body: unknown) {
  return vi.fn().mockResolvedValue({
    ok: status >= 200 && status < 300,
    status,
    statusText: "",
    json: async () => body,
  });
}

function clearMarkerCookie() {
  document.cookie = "helbaron_authed=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT";
}

afterEach(() => {
  vi.restoreAllMocks();
  clearMarkerCookie();
});

describe("apiFetch", () => {
  it("returns the parsed success envelope", async () => {
    vi.stubGlobal("fetch", mockFetch(200, { data: { id: "1" } }));
    const res = await apiFetch<{ data: { id: string } }>("courses");
    expect(res.data.id).toBe("1");
  });

  it("routes browser requests through the same-origin BFF proxy", async () => {
    const fetchMock = mockFetch(200, { data: null });
    vi.stubGlobal("fetch", fetchMock);
    await apiFetch("profile");
    expect(fetchMock.mock.calls[0][0]).toBe("/api/backend/profile");
  });

  it("never attaches an Authorization header from browser code (token is httpOnly)", async () => {
    const fetchMock = mockFetch(200, { data: null });
    vi.stubGlobal("fetch", fetchMock);
    await apiFetch("profile");
    const init = fetchMock.mock.calls[0][1] as RequestInit;
    const headers = init.headers as Record<string, string>;
    expect(headers.Authorization).toBeUndefined();
    expect(init.credentials).toBe("same-origin");
  });

  it("throws a typed ApiRequestError on the standard error envelope", async () => {
    vi.stubGlobal(
      "fetch",
      mockFetch(422, { error: { code: "VALIDATION", message: "Invalid", correlation_id: "c1", timestamp: "t" } }),
    );
    await expect(apiFetch("courses")).rejects.toMatchObject({
      name: "ApiRequestError",
      status: 422,
      code: "VALIDATION",
    });
  });

  it("keeps the explanation a framework-rendered refusal wrote outside the envelope", async () => {
    // A Symfony HttpException answers `{ "message": "..." }` with no `error` key. Falling through
    // to statusText would replace a written reason with the word "Forbidden".
    vi.stubGlobal("fetch", mockFetch(403, { message: "You do not have access to this course." }));
    await expect(apiFetch("courses/x/questions")).rejects.toMatchObject({
      status: 403,
      code: "HTTP_ERROR",
      message: "You do not have access to this course.",
    });
  });
});

describe("session helpers", () => {
  it("hasSession reflects the marker cookie only", () => {
    expect(hasSession()).toBe(false);
    document.cookie = "helbaron_authed=1; path=/";
    expect(hasSession()).toBe(true);
  });

  it("sessionLogin posts credentials to /api/session and returns the user", async () => {
    const fetchMock = mockFetch(200, { data: { user: { id: "u1" } } });
    vi.stubGlobal("fetch", fetchMock);
    const res = await sessionLogin({ email: "a@b.c", password: "pw", device_name: "web" });
    expect(fetchMock.mock.calls[0][0]).toBe("/api/session");
    expect((fetchMock.mock.calls[0][1] as RequestInit).method).toBe("POST");
    expect(res.user).toMatchObject({ id: "u1" });
  });

  it("sessionLogin surfaces the API error envelope (e.g. MFA required)", async () => {
    vi.stubGlobal("fetch", mockFetch(403, { error: { code: "MFA_REQUIRED", message: "MFA required" } }));
    await expect(sessionLogin({ email: "a@b.c", password: "pw" })).rejects.toMatchObject({
      status: 403,
      code: "MFA_REQUIRED",
    });
  });

  it("sessionLogout deletes the session", async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 204, statusText: "", json: async () => null });
    vi.stubGlobal("fetch", fetchMock);
    await sessionLogout();
    expect(fetchMock.mock.calls[0][0]).toBe("/api/session");
    expect((fetchMock.mock.calls[0][1] as RequestInit).method).toBe("DELETE");
  });
});

describe("ApiRequestError", () => {
  it("carries status and code", () => {
    const e = new ApiRequestError(404, "NOT_FOUND", "Missing");
    expect(e.status).toBe(404);
    expect(e.code).toBe("NOT_FOUND");
  });
});
