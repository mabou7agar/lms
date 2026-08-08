<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Certificate DESIGNER + visual-immutability columns.
 *
 *  • certificate_templates.design — designer-owned image references (background, company logo,
 *    signature image(s)) + additional signatory text. A single JSON blob keeps the schema narrow.
 *  • certificates.rendered_snapshot — the frozen template body (+ design + orientation) captured at
 *    issuance so a later template edit never changes an already-issued certificate's document.
 *  • certificates.template_version — the template version an issued certificate was rendered from.
 *
 * The signed-evidence payload (number|verification_code|user_id|course_id|issued_at) is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->json('design')->nullable()->after('html_i18n');
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->unsignedInteger('template_version')->nullable()->after('template_id');
            $table->json('rendered_snapshot')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->dropColumn('design');
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(['template_version', 'rendered_snapshot']);
        });
    }
};
