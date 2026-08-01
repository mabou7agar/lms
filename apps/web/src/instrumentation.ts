/**
 * Next.js instrumentation hook. `register()` runs once when the server process boots
 * (`node server.js` / `next start`), NOT during `next build`, so a hard failure here safely
 * aborts a misconfigured production start without ever breaking the build or the test run.
 */
export async function register() {
  // Only the Node.js server runtime has the full process environment; skip the edge runtime.
  if (process.env.NEXT_RUNTIME !== "nodejs") return;
  const { validateWebEnv } = await import("./lib/env");
  validateWebEnv(process.env, { throwOnError: process.env.NODE_ENV === "production" });
}
