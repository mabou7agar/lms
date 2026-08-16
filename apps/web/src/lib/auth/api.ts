import { api } from "@/lib/api/client";
import type { ApiSuccess, AuthUser } from "@/types/api";
import type { Locale } from "@/lib/i18n/config";

/**
 * The company an account is registered on behalf of. Only the name is required — the rest can be
 * completed later from the manager portal, so a signup is never blocked on a tax id.
 */
export type CompanyRegistrationInput = {
  name: string;
  size?: string;
  country?: string;
  industry?: string;
  phone?: string;
  tax_id?: string;
  billing_address?: string;
};

export type RegisterInput = {
  name: string;
  email: string;
  phone?: string;
  password: string;
  password_confirmation: string;
  locale?: Locale;
  /** Omitted for a personal account; `company` registers the organization in the same step. */
  account_type?: "personal" | "company";
  company?: CompanyRegistrationInput;
};

/** POST /auth/register — creates the account and emits the email OTP. Returns the user (no token). */
export function registerUser(input: RegisterInput) {
  return api.post<ApiSuccess<AuthUser>>("auth/register", input, { auth: false });
}

/** POST /auth/forgot-password — always succeeds (no account enumeration). */
export function forgotPassword(email: string) {
  return api.post("auth/forgot-password", { email }, { auth: false });
}

/** POST /auth/reset-password */
export function resetPassword(input: {
  token: string;
  email: string;
  password: string;
  password_confirmation: string;
}) {
  return api.post("auth/reset-password", input, { auth: false });
}

/** POST /auth/verify-email — requires a bearer token (attached automatically). */
export function verifyEmail(code: string) {
  return api.post("auth/verify-email", { code });
}

/** POST /auth/mfa/verify — step-up verification for an authenticated session. */
export function verifyMfa(code: string) {
  return api.post("auth/mfa/verify", { code });
}
