"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { errorMessage } from "@/lib/api/errors";
import { useI18n } from "@/lib/i18n/i18n-context";
import { useProfile, useUpdateProfile } from "@/lib/student/hooks";
import type { UserProfile } from "@/lib/student/api";
import { PageHeader } from "@/components/student/page-header";
import { QueryState } from "@/components/student/query-state";
import { Field } from "@/components/auth/field";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { toast } from "@/components/ui/toast";

type Values = {
  name: string;
  first_name: string;
  last_name: string;
  bio: string;
  gender: "" | "male" | "female" | "unspecified";
  date_of_birth: string;
  country: string;
  city: string;
  locale: "en" | "ar";
};

const controlClass =
  "flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2";

/**
 * Curated country list mapping a localized display name to its ISO 3166-1 alpha-2 code.
 * MENA-first, followed by other major markets. Kept short and human-editable on purpose.
 */
const COUNTRIES: { code: string; en: string; ar: string }[] = [
  { code: "EG", en: "Egypt", ar: "مصر" },
  { code: "SA", en: "Saudi Arabia", ar: "السعودية" },
  { code: "AE", en: "United Arab Emirates", ar: "الإمارات" },
  { code: "QA", en: "Qatar", ar: "قطر" },
  { code: "KW", en: "Kuwait", ar: "الكويت" },
  { code: "BH", en: "Bahrain", ar: "البحرين" },
  { code: "OM", en: "Oman", ar: "عُمان" },
  { code: "JO", en: "Jordan", ar: "الأردن" },
  { code: "LB", en: "Lebanon", ar: "لبنان" },
  { code: "PS", en: "Palestine", ar: "فلسطين" },
  { code: "IQ", en: "Iraq", ar: "العراق" },
  { code: "SY", en: "Syria", ar: "سوريا" },
  { code: "YE", en: "Yemen", ar: "اليمن" },
  { code: "MA", en: "Morocco", ar: "المغرب" },
  { code: "DZ", en: "Algeria", ar: "الجزائر" },
  { code: "TN", en: "Tunisia", ar: "تونس" },
  { code: "LY", en: "Libya", ar: "ليبيا" },
  { code: "SD", en: "Sudan", ar: "السودان" },
  { code: "TR", en: "Türkiye", ar: "تركيا" },
  { code: "US", en: "United States", ar: "الولايات المتحدة" },
  { code: "GB", en: "United Kingdom", ar: "المملكة المتحدة" },
  { code: "CA", en: "Canada", ar: "كندا" },
  { code: "DE", en: "Germany", ar: "ألمانيا" },
  { code: "FR", en: "France", ar: "فرنسا" },
  { code: "ES", en: "Spain", ar: "إسبانيا" },
  { code: "IT", en: "Italy", ar: "إيطاليا" },
  { code: "NL", en: "Netherlands", ar: "هولندا" },
  { code: "IN", en: "India", ar: "الهند" },
  { code: "PK", en: "Pakistan", ar: "باكستان" },
  { code: "CN", en: "China", ar: "الصين" },
  { code: "JP", en: "Japan", ar: "اليابان" },
  { code: "AU", en: "Australia", ar: "أستراليا" },
  { code: "BR", en: "Brazil", ar: "البرازيل" },
  { code: "ZA", en: "South Africa", ar: "جنوب أفريقيا" },
  { code: "NG", en: "Nigeria", ar: "نيجيريا" },
  { code: "KE", en: "Kenya", ar: "كينيا" },
];

function ProfileForm({ data }: { data: UserProfile }) {
  const { t, locale } = useI18n();
  const update = useUpdateProfile();

  const schema = z.object({
    name: z.string().optional(),
    first_name: z.string().optional(),
    last_name: z.string().optional(),
    bio: z.string().max(1000).optional(),
    gender: z.enum(["", "male", "female", "unspecified"]).optional(),
    date_of_birth: z.string().optional(),
    country: z.string().max(2).optional(),
    city: z.string().max(120).optional(),
    locale: z.enum(["en", "ar"]),
  });

  const { register, handleSubmit, reset } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: data.name ?? "",
      first_name: data.profile?.first_name ?? "",
      last_name: data.profile?.last_name ?? "",
      bio: data.profile?.bio ?? "",
      gender: (data.profile?.gender as Values["gender"]) ?? "",
      date_of_birth: data.profile?.date_of_birth ?? "",
      country: data.profile?.country ?? "",
      city: data.profile?.city ?? "",
      locale: data.locale ?? "en",
    },
  });

  useEffect(() => {
    reset({
      name: data.name ?? "",
      first_name: data.profile?.first_name ?? "",
      last_name: data.profile?.last_name ?? "",
      bio: data.profile?.bio ?? "",
      gender: (data.profile?.gender as Values["gender"]) ?? "",
      date_of_birth: data.profile?.date_of_birth ?? "",
      country: data.profile?.country ?? "",
      city: data.profile?.city ?? "",
      locale: data.locale ?? "en",
    });
  }, [data, reset]);

  const onSubmit = handleSubmit((v) => {
    update.mutate(
      {
        name: v.name || undefined,
        first_name: v.first_name || null,
        last_name: v.last_name || null,
        bio: v.bio || null,
        gender: v.gender ? v.gender : null,
        date_of_birth: v.date_of_birth || null,
        country: v.country || null,
        city: v.city || null,
        locale: v.locale,
      },
      {
        onSuccess: () => toast.success(t("student.profile.saved")),
        onError: (e) => toast.error(errorMessage(e, t("common.error"))),
      },
    );
  });

  const L = (en: string, ar: string) => (locale === "ar" ? ar : en);

  return (
    <Card className="border-border/70">
      <CardContent className="p-6">
        <form onSubmit={onSubmit} className="space-y-4">
          <h2 className="font-serif text-lg font-semibold">{L("Personal details", "البيانات الشخصية")}</h2>
          <Field id="name" label={t("student.profile.name")}>
            <Input id="name" {...register("name")} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field id="first_name" label={t("student.profile.firstName")}>
              <Input id="first_name" {...register("first_name")} />
            </Field>
            <Field id="last_name" label={t("student.profile.lastName")}>
              <Input id="last_name" {...register("last_name")} />
            </Field>
          </div>
          <Field id="bio" label={t("student.profile.bio")}>
            <Textarea id="bio" rows={3} {...register("bio")} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field id="gender" label={t("student.profile.gender")}>
              <select id="gender" className={controlClass} {...register("gender")}>
                <option value="">—</option>
                <option value="male">{t("student.profile.genderMale")}</option>
                <option value="female">{t("student.profile.genderFemale")}</option>
                <option value="unspecified">{t("student.profile.genderUnspecified")}</option>
              </select>
            </Field>
            <Field id="date_of_birth" label={t("student.profile.dob")}>
              <Input id="date_of_birth" type="date" {...register("date_of_birth")} />
            </Field>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field id="country" label={t("student.profile.country")}>
              <select id="country" className={controlClass} {...register("country")}>
                <option value="">—</option>
                {COUNTRIES.map((c) => (
                  <option key={c.code} value={c.code}>
                    {locale === "ar" ? c.ar : c.en}
                  </option>
                ))}
              </select>
            </Field>
            <Field id="city" label={t("student.profile.city")}>
              <Input id="city" {...register("city")} />
            </Field>
          </div>
          <div className="border-t border-border/60 pt-4">
            <h2 className="mb-4 font-serif text-lg font-semibold">{L("Preferences", "التفضيلات")}</h2>
            <Field id="locale" label={t("student.profile.language")}>
              <select id="locale" className={controlClass} {...register("locale")}>
                <option value="en">English</option>
                <option value="ar">العربية</option>
              </select>
            </Field>
          </div>
          <Button type="submit" loading={update.isPending} className="shine relative overflow-hidden">
            {t("student.profile.save")}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

export default function ProfilePage() {
  const { t } = useI18n();
  const query = useProfile();
  return (
    <div className="max-w-2xl">
      <PageHeader eyebrow="ACCOUNT" icon="User" title={t("student.profile.title")} subtitle={t("student.profile.subtitle")} />
      <QueryState query={query}>{(data) => <ProfileForm data={data} />}</QueryState>
    </div>
  );
}
