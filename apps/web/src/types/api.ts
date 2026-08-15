/** The ONE standard success/error envelope returned by the HElbaron API. */
export type ApiSuccess<T> = { data: T; message?: string; meta?: Record<string, unknown> };

export type ApiError = {
  error: {
    code: string;
    message: string;
    details?: Record<string, unknown>;
    correlation_id: string;
    timestamp: string;
  };
};

export type Paginated<T> = {
  data: T[];
  meta: { current_page: number; per_page: number; total: number; last_page: number };
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
};

export type AuthUser = {
  id: string;
  name: string;
  email: string;
  locale: "en" | "ar";
  roles: string[];
  /** Enterprise manager-portal capability hint (owner/admin or dept/team manager in any org). */
  is_org_manager?: boolean;
  email_verified: boolean;
  mfa_enabled: boolean;
};
