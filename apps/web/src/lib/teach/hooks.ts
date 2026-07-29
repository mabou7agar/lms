"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  archiveCourse,
  createAnnouncement,
  getAuthoringActivity,
  getCourseChanges,
  getCoursePerformance,
  getCourseReadiness,
  getDashboardOverview,
  getInstructorAlerts,
  getTeachAnnouncements,
  getTeachCourse,
  getTeachCourses,
  getTeachDashboard,
  getTeachStudents,
  publishCourse,
  unpublishCourse,
  type AnnouncementInput,
  type CourseStatus,
  type PerformanceFilters,
} from "./api";

export const useTeachDashboard = () =>
  useQuery({ queryKey: ["teach", "dashboard"], queryFn: getTeachDashboard });

export const useDashboardOverview = (from?: string, to?: string) =>
  useQuery({
    queryKey: ["teach", "overview", from ?? null, to ?? null],
    queryFn: () => getDashboardOverview(from, to),
  });

/**
 * Paginated course performance.
 *
 * The whole filter object is in the query key, so changing any filter is a distinct cache entry
 * rather than a mutation of one — which is what lets TanStack cancel the in-flight request and
 * keeps a slow response for "Lara" from overwriting the results for "Laravel".
 *
 * `placeholderData` keeps the previous page on screen while the next one loads, so paging does not
 * collapse the table into a skeleton on every click.
 */
export const useCoursePerformance = (filters: PerformanceFilters) =>
  useQuery({
    queryKey: ["teach", "performance", filters],
    queryFn: () => getCoursePerformance(filters),
    placeholderData: (previous) => previous,
  });

export const useAuthoringActivity = () =>
  useQuery({ queryKey: ["teach", "activity"], queryFn: getAuthoringActivity });

export const useInstructorAlerts = () =>
  useQuery({ queryKey: ["teach", "alerts"], queryFn: getInstructorAlerts });

/**
 * Draft-vs-published change summary. Fetched only when a panel asks for it: it reports unavailable
 * for every course today, so eagerly requesting it for a whole table would be pure waste.
 */
export const useCourseChanges = (id: string, enabled = true) =>
  useQuery({
    queryKey: ["teach", "changes", id],
    queryFn: () => getCourseChanges(id),
    enabled: !!id && enabled,
  });

export const useTeachCourses = (status?: CourseStatus) =>
  useQuery({ queryKey: ["teach", "courses", status ?? "all"], queryFn: () => getTeachCourses(status) });

export const useTeachCourse = (id: string) =>
  useQuery({ queryKey: ["teach", "course", id], queryFn: () => getTeachCourse(id), enabled: !!id });

export const useTeachStudents = (id: string, page: number) =>
  useQuery({
    queryKey: ["teach", "students", id, page],
    queryFn: () => getTeachStudents(id, page),
    enabled: !!id,
  });

export const useTeachAnnouncements = (id: string) =>
  useQuery({
    queryKey: ["teach", "announcements", id],
    queryFn: () => getTeachAnnouncements(id),
    enabled: !!id,
  });

/**
 * Publish readiness. Not cached beyond the session: an author edits the curriculum and comes
 * straight back to this panel, so a stale report would tell them their fix did not work.
 */
export const useCourseReadiness = (id: string, enabled = true) =>
  useQuery({
    queryKey: ["teach", "readiness", id],
    queryFn: () => getCourseReadiness(id),
    enabled: !!id && enabled,
    staleTime: 0,
  });

/** Invalidate the course lists + a single course view after a lifecycle change. */
function useLifecycleMutation(fn: (id: string) => Promise<unknown>) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => fn(id),
    onSuccess: (_data, id) => {
      qc.invalidateQueries({ queryKey: ["teach", "courses"] });
      qc.invalidateQueries({ queryKey: ["teach", "course", id] });
      qc.invalidateQueries({ queryKey: ["teach", "dashboard"] });
      // Dashboard 2.0 surfaces. A lifecycle change moves a course between status buckets, changes
      // what the alerts panel should be complaining about, and adds an entry to the activity feed —
      // so all three are refetched rather than left showing the pre-action world.
      qc.invalidateQueries({ queryKey: ["teach", "overview"] });
      qc.invalidateQueries({ queryKey: ["teach", "performance"] });
      qc.invalidateQueries({ queryKey: ["teach", "activity"] });
      qc.invalidateQueries({ queryKey: ["teach", "alerts"] });
      // Publishing does not change readiness, but unpublishing and archiving can change what the
      // panel should offer next — and a refetch after any lifecycle change is cheap insurance
      // against showing an author a verdict that no longer applies.
      qc.invalidateQueries({ queryKey: ["teach", "readiness", id] });
    },
  });
}

export const usePublishCourse = () => useLifecycleMutation(publishCourse);
export const useUnpublishCourse = () => useLifecycleMutation(unpublishCourse);
export const useArchiveCourse = () => useLifecycleMutation(archiveCourse);

export function useCreateAnnouncement(id: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: AnnouncementInput) => createAnnouncement(id, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["teach", "announcements", id] }),
  });
}
