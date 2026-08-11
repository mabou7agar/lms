<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enriches crm_leads for the public enterprise-lead funnel: marketing attribution (UTM/gclid/
 * referrer/landing_path), qualification fields (request_type/company_size/country/company_name),
 * follow-up timestamps, a computed lead_score, and a self-contained marketing-consent record
 * (guest leads have no user account, so consent lives on the lead — never via Identity's
 * user_consents, which is out of CRM's bounded context).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->string('company_name')->nullable();
            $table->string('request_type', 16)->nullable();
            $table->string('company_size', 32)->nullable();
            $table->string('country', 2)->nullable();

            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('gclid')->nullable();
            $table->string('referrer', 2048)->nullable();
            $table->string('landing_path', 2048)->nullable();

            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->integer('lead_score')->nullable();

            $table->boolean('marketing_consent')->default(false);
            $table->string('consent_version', 32)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->string('consent_ip', 45)->nullable();

            // Dedup lookup (same email within a short window) hits this index.
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropIndex(['email']);
            $table->dropColumn([
                'company_name', 'request_type', 'company_size', 'country',
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                'gclid', 'referrer', 'landing_path',
                'next_follow_up_at', 'last_contacted_at', 'lead_score',
                'marketing_consent', 'consent_version', 'consented_at', 'consent_ip',
            ]);
        });
    }
};
