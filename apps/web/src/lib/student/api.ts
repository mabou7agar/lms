import { api } from "@/lib/api/client";
import type { ApiSuccess, Paginated } from "@/types/api";
import type { Locale } from "@/lib/i18n/config";

export type CourseRef = { id: string; title: string; slug?: string; thumbnail_path?: string | null };

export type MyLearningItem = {
  enrollment_id: string;
  status: string;
  progress_percentage: number;
  enrolled_at: string | null;
  completed_at: string | null;
  course: CourseRef;
  /** How this access was obtained. `company_seat` came out of an employer's purchase. */
  source: string | null;
  company_granted: boolean;
  /** When the access window closes. Null for anything the learner obtained themselves. */
  expires_at: string | null;
  expired: boolean;
};

export type ContinueLearningItem = {
  course: { id: string; title: string };
  progress_percentage: number;
  next_lesson: { id: string; title: string; type: string } | null;
};

export type CertificateItem = {
  id: string;
  number: string;
  /** Effective status: "issued" | "revoked" | "expired". */
  status: string;
  course_title: string | null;
  issued_at: string | null;
  /** When the credential stops being valid. Null means it never lapses. */
  expires_at: string | null;
  expired: boolean;
  /** Set only when the certificate carries the company's marks. */
  company_name: string | null;
  company_branded: boolean;
};

export type NotificationItem = {
  id: string;
  category: string;
  type: string;
  title: string;
  body: string;
  data?: Record<string, unknown> | null;
  locale: string;
  read: boolean;
  archived: boolean;
  created_at: string | null;
};

export type UserProfile = {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  locale: Locale;
  email_verified: boolean;
  mfa_enabled: boolean;
  roles: string[];
  profile: {
    first_name: string | null;
    last_name: string | null;
    avatar_path: string | null;
    bio: string | null;
    gender: string | null;
    date_of_birth: string | null;
    /** ISO 3166-1 alpha-2 country code (size 2), e.g. "EG". */
    country?: string | null;
    city?: string | null;
  } | null;
};

export type ProfileUpdate = {
  name?: string;
  locale?: Locale;
  first_name?: string | null;
  last_name?: string | null;
  bio?: string | null;
  gender?: string | null;
  date_of_birth?: string | null;
  /** ISO 3166-1 alpha-2 country code (size 2). */
  country?: string | null;
  city?: string | null;
};

export type PreferencesUpdate = {
  locale?: Locale;
  digest_frequency?: string;
  timezone?: string;
  /**
   * Quiet-hours window (interpreted in `timezone`). Times are "HH:MM"; the window may wrap midnight.
   * Marketing/non-critical messages that land inside the window are deferred, never dropped;
   * transactional/critical messages ignore quiet hours entirely.
   *
   * NOTE: the backend `notifications/preferences` endpoint (UpdatePreferencesRequest) does not yet
   * accept these keys, so they are dropped by the server's `validated()` on save until the endpoint
   * is extended to persist the `user_notification_settings` quiet-hours columns.
   */
  quiet_hours_enabled?: boolean;
  quiet_hours_start?: string | null;
  quiet_hours_end?: string | null;
};

// ---- Reads (unwrap `.data`) ----
export const getMyLearning = () => api.data<MyLearningItem[]>("my-learning");
export const getContinueLearning = () => api.data<ContinueLearningItem[]>("continue-learning");
export const getMyCertificates = () => api.data<CertificateItem[]>("my-certificates");
export const getProfile = () => api.data<UserProfile>("profile");
export const getNotifications = (page = 1) =>
  api.get<Paginated<NotificationItem>>(`notifications?page=${page}`);

// ---- Writes ----
export const updateProfile = (input: ProfileUpdate) => api.put<ApiSuccess<UserProfile>>("profile", input);
export const markNotificationRead = (id: string) => api.post(`notifications/${id}/read`);
export const updatePreferences = (input: PreferencesUpdate) => api.post("notifications/preferences", input);
export const requestCertificateDownload = (id: string) =>
  api.post<ApiSuccess<{ download_url: string }>>(`certificates/${id}/download`);
export const requestCertificateShare = (id: string) =>
  api.post<ApiSuccess<Record<string, unknown>>>(`certificates/${id}/share`);
