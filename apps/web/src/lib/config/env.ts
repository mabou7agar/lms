/**
 * Environment validation. Fails fast with an actionable message when a required value is missing,
 * and — critically — refuses to let a clearly-private value be exposed to the browser through a
 * `NEXT_PUBLIC_` prefix. Call {@link assertEnv} from `next.config`/server bootstrap so a
 * misconfigured build/start stops immediately instead of shipping a broken or leaky app.
 */

/** Public values the browser bundle needs. Must be present at build time. */
export const REQUIRED_PUBLIC = ["NEXT_PUBLIC_API_BASE_URL"] as const;

/** Server-only values the Node runtime (SSR + BFF proxy) needs. Checked only on the server. */
export const REQUIRED_SERVER = ["API_INTERNAL_URL"] as const;

/**
 * Substrings that mark an unambiguously PRIVATE value. A `NEXT_PUBLIC_`-prefixed variable whose name
 * contains one of these is almost certainly a secret leaking into the client bundle. Kept narrow on
 * purpose (no generic KEY/TOKEN) so legitimately public keys — e.g. a payment publishable key — do
 * not trip it.
 */
const PRIVATE_MARKERS = ["SECRET", "PASSWORD", "PRIVATE", "SERVICE_ROLE"];

export interface EnvCheckOptions {
  /** When true, also require the server-only variables. Defaults to "are we on the server". */
  requireServer?: boolean;
}

/**
 * @returns a list of human-readable problems; empty when the environment is valid.
 */
export function validateEnv(
  env: Record<string, string | undefined> = process.env,
  options: EnvCheckOptions = {},
): string[] {
  const requireServer = options.requireServer ?? typeof window === "undefined";
  const errors: string[] = [];

  for (const key of REQUIRED_PUBLIC) {
    if (!env[key]?.trim()) errors.push(`Missing required public variable ${key}.`);
  }
  if (requireServer) {
    for (const key of REQUIRED_SERVER) {
      if (!env[key]?.trim()) errors.push(`Missing required server variable ${key}.`);
    }
  }

  for (const key of Object.keys(env)) {
    if (!key.startsWith("NEXT_PUBLIC_")) continue;
    const upper = key.toUpperCase();
    if (PRIVATE_MARKERS.some((marker) => upper.includes(marker))) {
      errors.push(`${key} looks private but is exposed to the browser via the NEXT_PUBLIC_ prefix — rename it without NEXT_PUBLIC_.`);
    }
  }

  return errors;
}

/** Throws with an actionable, multi-line message when the environment is invalid. */
export function assertEnv(env: Record<string, string | undefined> = process.env): void {
  const errors = validateEnv(env);
  if (errors.length > 0) {
    throw new Error(
      "Invalid environment configuration:\n" + errors.map((e) => `  - ${e}`).join("\n"),
    );
  }
}
