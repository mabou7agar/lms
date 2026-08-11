<?php

/*
 | CRM domain configuration. Independent of Learning/Commerce. No marketing/AI/reports.
 */
return [
    'consulting' => [
        'sla_hours' => (int) env('CRM_CONSULTING_SLA_HOURS', 48),
    ],
    'search' => [
        'min_query_length' => 2,
    ],
    'pipeline' => [
        'default_stages' => ['New', 'Contacted', 'Qualified', 'Proposal', 'Won', 'Lost'],
    ],

    /*
     | Public enterprise-lead funnel (guest POST /api/v1/public/leads).
     */
    'public_lead' => [
        // Rate limiter: max submissions per minute, keyed by IP + email.
        'rate_limit_per_minute' => (int) env('CRM_PUBLIC_LEAD_RATE_LIMIT', 10),
        // Dedup window: a repeat submission (same email + company) inside this window updates the
        // existing lead instead of creating a duplicate.
        'dedup_window_minutes' => (int) env('CRM_PUBLIC_LEAD_DEDUP_MINUTES', 60),
        // Marketing-consent copy version stamped onto the lead when consent is granted.
        'consent_version' => (string) env('CRM_PUBLIC_LEAD_CONSENT_VERSION', '2026-08-09'),
        // Optional default owner (internal user id) for round-robin/queue assignment; null = unassigned.
        'default_owner_id' => env('CRM_PUBLIC_LEAD_DEFAULT_OWNER_ID'),
        // Source label stamped on funnel leads.
        'source' => 'enterprise_funnel',
    ],

    /*
     | Deterministic lead-scoring rules (LeadScoringService). Scores are additive and clamped to
     | [0, 100]. Everything here is data — no code changes to re-tune the model.
     */
    'scoring' => [
        'base' => 10,
        'max' => 100,
        'request_type' => [
            'demo' => 40,
            'pricing' => 30,
            'partnership' => 25,
            'contact' => 10,
        ],
        'company_size' => [
            '1-10' => 5,
            '11-50' => 15,
            '51-200' => 25,
            '201-500' => 30,
            '501-1000' => 35,
            '1000+' => 40,
        ],
        // Points by acquisition channel (utm_medium, case-insensitive).
        'utm_medium' => [
            'cpc' => 20,
            'paid' => 20,
            'ppc' => 20,
            'email' => 15,
            'referral' => 15,
            'organic' => 10,
            'social' => 8,
        ],
        // A known paid-click id (gclid) signals a high-intent paid visitor.
        'has_gclid' => 15,
        // Bonus when a business (non free-mail) email domain is supplied.
        'business_email' => 10,
        // Free-mail domains that do NOT earn the business-email bonus.
        'free_email_domains' => [
            'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com',
            'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.com', 'yandex.com',
        ],
    ],

    /*
     | Enterprise manager portal (self-serve org operation).
     */
    'invitation' => [
        // Days a membership invitation token stays valid. 0 = never expires.
        'ttl_days' => (int) env('CRM_INVITATION_TTL_DAYS', 14),
    ],

    'import' => [
        // Hard limits on a CSV employee import (defense against oversized uploads).
        'max_bytes' => (int) env('CRM_IMPORT_MAX_BYTES', 2 * 1024 * 1024),
        'max_rows' => (int) env('CRM_IMPORT_MAX_ROWS', 5000),
    ],

    'reporting' => [
        // Default inactivity window (days) for the manager report's "inactive learners" metric.
        'inactive_days' => (int) env('CRM_REPORT_INACTIVE_DAYS', 30),
    ],
];
