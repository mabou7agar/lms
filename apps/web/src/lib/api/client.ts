import { siteConfig } from "@/config/site";
import { isLocale, localeCookieName } from "@/lib/i18n/config";
import type { ApiError, ApiSuccess, AuthUser } from "@/types/api";

/**
 * API access goes through the same-origin BFF proxy (/api/backend/*), which attaches the
 * Sanctum token from an httpOnly cookie server-side. Browser JS never sees the token
 * (mitigates XSS token exfiltration). Login/logout use /api/session.
 */
const MARKER_COOKIE = "helbaron_authed";
const DEFAULT_TIMEOUT_MS = 20_000;

/** True when a session marker cookie is present (the real credential is httpOnly). */
export function hasSession(): boolean {
  if (typeof document === "undefined") return false;
  return document.cookie.split("; ").some((c) => c.startsWith(`${MARKER_COOKIE}=1`));
}

/** Error thrown for any non-2xx response, carrying the standard error envelope. */
export class ApiRequestError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly details?: Record<string, unknown>,
    public readonly correlationId?: string,
    /**
     * The raw parsed response body. Kept so callers can read non-standard error shapes the
     * envelope does not model — e.g. the optimistic-concurrency 409 body
     * `{ error: "stale_write", current_version: <int> }` used by the curriculum builder.
     */
    public readonly payload?: unknown,
  ) {
    super(message);
    this.name = "ApiRequestError";
  }
}

type RequestOptions = Omit<RequestInit, "body"> & { body?: unknown; auth?: boolean };

function apiBase(): string {
  // In the browser, always go through the same-origin proxy; on the server, hit the API directly.
  return typeof window === "undefined" ? siteConfig.apiBaseUrl : "/api/backend";
}

/**
 * The user's selected UI locale, read from the persisted `locale` cookie in the browser (the same
 * cookie the i18n context writes on `setLocale`). Sent as `Accept-Language` so the API localizes
 * responses to match the chosen language. Server-side there is no `document`, so we return undefined
 * and leave the header unset — the BFF proxy forwards the browser's own `accept-language` for
 * client-originated calls. Reading a cookie in a request/event handler (not during render) cannot
 * cause a hydration mismatch.
 */
function selectedLocaleHeader(): string | undefined {
  if (typeof document === "undefined") return undefined;
  const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${localeCookieName}=([^;]+)`));
  const value = match ? decodeURIComponent(match[1]) : null;
  return isLocale(value) ? value : undefined;
}

async function parseAndThrow(res: Response): Promise<unknown> {
  const json = res.status === 204 ? null : await res.json().catch(() => null);

  if (!res.ok) {
    const err = (json as ApiError | null)?.error;
    throw new ApiRequestError(
      res.status,
      err?.code ?? "HTTP_ERROR",
      err?.message ?? res.statusText,
      err?.details,
      err?.correlation_id,
      json,
    );
  }

  return json;
}

/**
 * Typed fetch wrapper around the REST API (/api/v1 via the BFF proxy). Unwraps the standard
 * envelope and throws ApiRequestError on failure. Requests time out after 20s unless the
 * caller supplies its own AbortSignal.
 */
export async function apiFetch<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { body, auth: _auth, headers, signal, ...rest } = options;

  const timeout = signal ? null : new AbortController();
  const timer = timeout ? setTimeout(() => timeout.abort(), DEFAULT_TIMEOUT_MS) : null;

  const acceptLanguage = selectedLocaleHeader();

  try {
    const res = await fetch(`${apiBase()}/${path.replace(/^\//, "")}`, {
      ...rest,
      credentials: "same-origin",
      signal: signal ?? timeout?.signal,
      headers: {
        Accept: "application/json",
        ...(acceptLanguage ? { "Accept-Language": acceptLanguage } : {}),
        ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
        ...headers,
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    return (await parseAndThrow(res)) as T;
  } finally {
    if (timer) clearTimeout(timer);
  }
}

/** Logs in via the BFF session endpoint; the token is stored in an httpOnly cookie. */
export async function sessionLogin(payload: {
  email: string;
  password: string;
  mfa_code?: string;
  device_name?: string;
}): Promise<{ user: AuthUser }> {
  const res = await fetch("/api/session", {
    method: "POST",
    credentials: "same-origin",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  return ((await parseAndThrow(res)) as ApiSuccess<{ user: AuthUser }>).data;
}

/** Revokes the server-side token and clears the session cookies. */
export async function sessionLogout(): Promise<void> {
  const res = await fetch("/api/session", {
    method: "DELETE",
    credentials: "same-origin",
    headers: { Accept: "application/json" },
  });
  if (!res.ok && res.status !== 204) await parseAndThrow(res);
}

/** Convenience helpers returning the unwrapped `data` for success envelopes. */
export const api = {
  get: <T>(path: string, opts?: RequestOptions) => apiFetch<T>(path, { ...opts, method: "GET" }),
  post: <T>(path: string, body?: unknown, opts?: RequestOptions) =>
    apiFetch<T>(path, { ...opts, method: "POST", body }),
  put: <T>(path: string, body?: unknown, opts?: RequestOptions) =>
    apiFetch<T>(path, { ...opts, method: "PUT", body }),
  del: <T>(path: string, opts?: RequestOptions) => apiFetch<T>(path, { ...opts, method: "DELETE" }),
  /** Unwrap `{ data }` for endpoints using the success envelope. */
  data: async <T>(path: string, opts?: RequestOptions) =>
    (await apiFetch<ApiSuccess<T>>(path, { ...opts, method: opts?.method ?? "GET" })).data,
};

/**
 * Axios-style client returning `{ data }`, where `data` is the UNWRAPPED success-envelope payload
 * (the backend `{ data: ... }` already peeled). The media direct-upload transport and the grader
 * file-access flow (uploadClient / SubmissionFileList) are written against a `{ data }`-returning
 * client, so this adapts the shared `api` helpers to that shape.
 */
export const apiClient = {
  get: async <T>(path: string, opts?: RequestOptions) => ({
    data: await api.data<T>(path, { ...opts, method: "GET" }),
  }),
  post: async <T>(path: string, body?: unknown, opts?: RequestOptions) => ({
    data: await api.data<T>(path, { ...opts, method: "POST", body }),
  }),
  put: async <T>(path: string, body?: unknown, opts?: RequestOptions) => ({
    data: await api.data<T>(path, { ...opts, method: "PUT", body }),
  }),
  del: async <T>(path: string, opts?: RequestOptions) => ({
    data: await api.data<T>(path, { ...opts, method: "DELETE" }),
  }),
};
