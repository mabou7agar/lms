"use client";

import { useMutation, useQuery } from "@tanstack/react-query";
import { useI18n } from "@/lib/i18n/i18n-context";
import {
  enrollInCourse,
  getCategories,
  getCourse,
  getCourses,
  getTrainer,
  getTrainers,
  type CourseFilters,
} from "./api";

// The API localizes responses by the request's Accept-Language (derived from the locale cookie), so
// every read is locale-scoped: including `locale` in the query key makes React Query refetch — with the
// new Accept-Language — when the user switches language, instead of serving the other language's cache.

export const useCourses = (filters: CourseFilters) => {
  const { locale } = useI18n();
  return useQuery({ queryKey: ["courses", locale, filters], queryFn: () => getCourses(filters) });
};
export const useFeaturedCourses = () => {
  const { locale } = useI18n();
  return useQuery({ queryKey: ["courses", locale, "featured"], queryFn: () => getCourses({ featured: true, per_page: 9 }) });
};
export const useCourse = (publicId: string) => {
  const { locale } = useI18n();
  return useQuery({ queryKey: ["course", locale, publicId], queryFn: () => getCourse(publicId), enabled: Boolean(publicId) });
};
export const useCategories = () => {
  const { locale } = useI18n();
  return useQuery({ queryKey: ["categories", locale], queryFn: getCategories });
};
export const useTrainers = () => {
  const { locale } = useI18n();
  return useQuery({ queryKey: ["trainers", locale], queryFn: getTrainers });
};
export const useTrainer = (publicId: string) => {
  const { locale } = useI18n();
  return useQuery({ queryKey: ["trainer", locale, publicId], queryFn: () => getTrainer(publicId), enabled: Boolean(publicId) });
};
export const useEnroll = () => useMutation({ mutationFn: (id: string) => enrollInCourse(id) });
