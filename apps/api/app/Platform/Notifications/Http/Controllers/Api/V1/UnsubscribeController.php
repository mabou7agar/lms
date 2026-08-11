<?php

namespace App\Platform\Notifications\Http\Controllers\Api\V1;

use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Notifications\Enums\SuppressionSource;
use App\Platform\Notifications\Models\MarketingSuppression;
use App\Platform\Shared\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, unauthenticated unsubscribe endpoint. The route carries a SIGNED URL (the `signed`
 * middleware requires a valid signature), so the token is required and single-purpose: its signature
 * covers the exact email + category + tenant and cannot be edited to target another recipient or
 * category.
 *
 * NEVER suppresses transactional/critical categories: only the marketing category is suppressible, so
 * a tampered or hand-crafted request for a transactional category is rejected outright. Recording is
 * idempotent (unique per org+email+category) and stamps the source + timestamp.
 */
class UnsubscribeController
{
    public function __invoke(Request $request): JsonResponse
    {
        $email = trim((string) $request->query('email', ''));
        $category = (string) $request->query('category', NotificationCategory::Marketing->value);
        $orgRaw = $request->query('org');
        $organizationId = ($orgRaw === null || $orgRaw === '') ? null : (int) $orgRaw;

        if ($email === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing email.'], 422);
        }

        // Never suppress transactional/critical messages — only marketing is unsubscribable.
        $categoryEnum = NotificationCategory::tryFrom($category);

        if ($categoryEnum === null || ! $categoryEnum->isMarketing()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only marketing communications can be unsubscribed.',
            ], 422);
        }

        MarketingSuppression::query()
            ->withoutGlobalScope(TenantScope::class)
            ->updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'email' => $email,
                    'category' => $categoryEnum->value,
                ],
                [
                    'source' => SuppressionSource::UnsubscribeLink->value,
                    'suppressed_at' => now(),
                ],
            );

        return response()->json([
            'status' => 'ok',
            'message' => 'You have been unsubscribed from marketing communications.',
            'email' => $email,
            'category' => $categoryEnum->value,
        ]);
    }
}
