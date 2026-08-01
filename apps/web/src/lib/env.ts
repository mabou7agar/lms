/**
 * Frontend production environment contract.
 *
 * Two classes of variable:
 *  - PUBLIC (NEXT_PUBLIC_*): inlined into the client bundle at build time. These are
 *    world-readable — NEVER put a secret in one.
 *  - SERVER-ONLY: present only in the Node.js runtime (route handlers, server components,
 *    middleware). Safe for secrets; never shipped to the browser.
 *
 * `validateWebEnv()` fails fast in production when a required variable is missing or unsafe,
 * and ALWAYS refuses when something secret-shaped has been exposed through a NEXT_PUBLIC_* name.
 * It is called at server startup from `src/instrumentation.ts`, is safe to call in tests, and
 * never prints a value.
 */

export type EnvIssue = { key: string; problem: string };

// Public config the browser genuinely needs. Absent/empty in production => hard fail.
const REQUIRED_PUBLIC = ["NEXT_PUBLIC_API_BASE_URL", "NEXT_PUBLIC_SITE_URL"] as const;

// Public config that must parse as an absolute URL when present.
const URL_PUBLIC = ["NEXT_PUBLIC_API_BASE_URL", "NEXT_PUBLIC_SITE_URL"] as const;

// Substrings that must never appear in a NEXT_PUBLIC_* variable NAME — they denote secrets that
// would be baked into the client bundle for anyone to read.
const SECRET_MARKERS = [
  "SECRET",
  "PRIVATE",
  "PASSWORD",
  "API_KEY",
  "APIKEY",
  "DSN",
  "CREDENTIAL",
  "ACCESS_KEY",
];

// NEXT_PUBLIC names explicitly allow-listed despite matching a marker (none by default).
const PUBLIC_ALLOWLIST = new Set<string>([]);

function isProd(env: NodeJS.ProcessEnv): boolean {
  return env.NODE_ENV === "production";
}

/** Pure inspection: returns every problem found, without throwing or logging. */
export function collectEnvIssues(env: NodeJS.ProcessEnv = process.env): EnvIssue[] {
  const issues: EnvIssue[] = [];

  // 1. Required public vars must be present and non-empty in production.
  if (isProd(env)) {
    for (const key of REQUIRED_PUBLIC) {
      const val = env[key];
      if (!val || val.trim() === "") {
        issues.push({ key, problem: "required in production but missing/empty" });
      }
    }
  }

  // 2. URL-shaped vars must parse when present.
  for (const key of URL_PUBLIC) {
    const val = env[key];
    if (val && val.trim() !== "") {
      try {
        // eslint-disable-next-line no-new
        new URL(val);
      } catch {
        issues.push({ key, problem: "must be a valid absolute URL" });
      }
    }
  }

  // 3. No secret-looking value may be exposed through a NEXT_PUBLIC_* name.
  for (const key of Object.keys(env)) {
    if (!key.startsWith("NEXT_PUBLIC_")) continue;
    if (PUBLIC_ALLOWLIST.has(key)) continue;
    const upper = key.toUpperCase();
    if (SECRET_MARKERS.some((m) => upper.includes(m))) {
      issues.push({
        key,
        problem: "secret-shaped name exposed as NEXT_PUBLIC (would leak into the client bundle)",
      });
    }
  }

  // 4. Production must not point the browser at a localhost API.
  if (isProd(env)) {
    const api = env.NEXT_PUBLIC_API_BASE_URL ?? "";
    if (/localhost|127\.0\.0\.1/.test(api)) {
      issues.push({
        key: "NEXT_PUBLIC_API_BASE_URL",
        problem: "still points at localhost in production",
      });
    }
  }

  return issues;
}

/**
 * Validate the environment. In production (or when `throwOnError` is set) an invalid environment
 * throws and aborts startup; otherwise problems are warned so local dev is never blocked.
 * Returns the issue list either way. Never prints a variable's value.
 */
export function validateWebEnv(
  env: NodeJS.ProcessEnv = process.env,
  opts: { throwOnError?: boolean } = {},
): EnvIssue[] {
  const issues = collectEnvIssues(env);
  const throwOnError = opts.throwOnError ?? isProd(env);
  if (issues.length > 0) {
    const lines = issues.map((i) => `  - ${i.key}: ${i.problem}`).join("\n");
    const msg = `Invalid frontend environment (${issues.length} issue(s)):\n${lines}`;
    if (throwOnError) {
      throw new Error(msg);
    }
    // eslint-disable-next-line no-console
    console.warn(`[env] ${msg}`);
  }
  return issues;
}
