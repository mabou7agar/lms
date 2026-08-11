<?php

declare(strict_types=1);

namespace App\Platform\AI\Enums;

/**
 * The AI-consuming feature a usage/quota/audit row is attributed to. Kept deliberately coarse so
 * later features (tutor, copilot, analytics assistant, semantic search) map onto a stable set that
 * cost dashboards and per-feature quotas can aggregate by without churn.
 */
enum AiFeature: string
{
    case Tutor = 'tutor';
    case Copilot = 'copilot';
    case AdminAssistant = 'admin_assistant';
    case Analytics = 'analytics';
    case Search = 'search';
    case Embedding = 'embedding';
    case Other = 'other';
}
