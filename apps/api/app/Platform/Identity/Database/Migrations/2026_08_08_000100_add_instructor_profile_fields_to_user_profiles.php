<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U4 - Instructor profile administration. Extends the existing user_profiles row (kept 1:1 with a
 * user) with the public-facing instructor presentation fields. All additive + nullable/defaulted, so
 * existing rows are untouched and every reader stays backward compatible:
 *
 *   • profile_photo / cover_photo  — MediaAsset references (the public_id string the shared MediaPicker
 *                                    stores). NEVER a signed URL; resolving to a URL is the Media
 *                                    platform's job (P1). Dual-read legacy path/URL values are tolerated.
 *   • headline_i18n / bio_i18n     — HasTranslations JSON maps (EN/AR). The legacy scalar `bio` column
 *                                    stays in sync via the trait on write.
 *   • specialties / social_links   — JSON arrays/maps.
 *   • website                      — canonical external link.
 *   • display_order                — ordering on the public instructor directory.
 *   • is_public                    — whether the profile is exposed on the public instructor pages.
 *                                    (Instructor *listing* eligibility is still governed by the user's
 *                                    is_active flag + the 'instructor' role — see UserLookupAdapter.)
 *
 * TENANCY NOTE (T1, later phase): user_profiles has no tenant/organization column yet. When tenant
 * scoping lands, the public instructor directory query in UserLookupAdapter::instructorProfiles() and
 * the MediaPicker owner scope on the admin form will both need an org filter; add the column here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->string('profile_photo')->nullable()->after('avatar_path');
            $table->string('cover_photo')->nullable()->after('profile_photo');
            $table->json('headline_i18n')->nullable()->after('bio');
            $table->json('bio_i18n')->nullable()->after('headline_i18n');
            $table->json('specialties')->nullable()->after('bio_i18n');
            $table->json('social_links')->nullable()->after('specialties');
            $table->string('website')->nullable()->after('social_links');
            $table->unsignedInteger('display_order')->default(0)->after('website');
            $table->boolean('is_public')->default(true)->after('display_order');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'profile_photo', 'cover_photo', 'headline_i18n', 'bio_i18n',
                'specialties', 'social_links', 'website', 'display_order', 'is_public',
            ]);
        });
    }
};
