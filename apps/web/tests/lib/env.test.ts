import { describe, it, expect } from "vitest";
import { collectEnvIssues, validateWebEnv } from "../../src/lib/env";

const base = {
  NODE_ENV: "production",
  NEXT_PUBLIC_API_BASE_URL: "https://api.example.com/api/v1",
  NEXT_PUBLIC_SITE_URL: "https://app.example.com",
} as unknown as NodeJS.ProcessEnv;

describe("frontend env contract", () => {
  it("passes with a valid production environment", () => {
    expect(collectEnvIssues(base)).toEqual([]);
  });

  it("fails when a required public var is missing in production", () => {
    const env = { ...base } as Record<string, string | undefined>;
    delete env.NEXT_PUBLIC_SITE_URL;
    const issues = collectEnvIssues(env as unknown as NodeJS.ProcessEnv);
    expect(issues.some((i) => i.key === "NEXT_PUBLIC_SITE_URL")).toBe(true);
  });

  it("flags a secret exposed through a NEXT_PUBLIC_* name", () => {
    const env = { ...base, NEXT_PUBLIC_STRIPE_SECRET: "sk_live_x" } as unknown as NodeJS.ProcessEnv;
    expect(collectEnvIssues(env).some((i) => i.key === "NEXT_PUBLIC_STRIPE_SECRET")).toBe(true);
  });

  it("rejects a localhost API base URL in production", () => {
    const env = {
      ...base,
      NEXT_PUBLIC_API_BASE_URL: "http://localhost:8000/api/v1",
    } as unknown as NodeJS.ProcessEnv;
    expect(collectEnvIssues(env).some((i) => i.problem.includes("localhost"))).toBe(true);
  });

  it("rejects a malformed URL", () => {
    const env = { ...base, NEXT_PUBLIC_SITE_URL: "not-a-url" } as unknown as NodeJS.ProcessEnv;
    expect(collectEnvIssues(env).some((i) => i.key === "NEXT_PUBLIC_SITE_URL")).toBe(true);
  });

  it("throws in production when the environment is invalid", () => {
    const env = { ...base } as Record<string, string | undefined>;
    delete env.NEXT_PUBLIC_API_BASE_URL;
    expect(() =>
      validateWebEnv(env as unknown as NodeJS.ProcessEnv, { throwOnError: true }),
    ).toThrow(/Invalid frontend environment/);
  });

  it("does not throw in development even when incomplete", () => {
    const env = { NODE_ENV: "development" } as unknown as NodeJS.ProcessEnv;
    expect(() => validateWebEnv(env)).not.toThrow();
  });
});
