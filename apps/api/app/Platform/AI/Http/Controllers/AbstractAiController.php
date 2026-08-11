<?php

declare(strict_types=1);

namespace App\Platform\AI\Http\Controllers;

use App\Platform\AI\Exceptions\AiDisabledException;
use App\Platform\AI\Exceptions\AiFeatureDisabledException;
use App\Platform\AI\Exceptions\AiQuotaExceededException;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Shared plumbing for the authenticated AI feature endpoints (tutor, copilot).
 *
 * Its one job beyond resolving the principal is to turn the AI foundation's fail-closed exceptions
 * into the platform's standard error envelope, so a governance kill-switch or an exhausted quota
 * becomes a CLEAR response instead of a 500:
 *   - a disabled feature/tenant/course => 503 with a machine-readable code and the governance reason;
 *   - an exceeded token quota          => 429 with the scope that tripped.
 * The provider itself is never reached in either case, so a blocked call costs nothing.
 */
abstract class AbstractAiController extends Controller
{
    /** The authenticated principal as an Actor. auth:sanctum guarantees one is present. */
    protected function actor(Request $request): Actor
    {
        $user = $request->user();

        if (! $user instanceof Actor) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        return $user;
    }

    /**
     * Run the guarded AI work, mapping foundation exceptions to the standard error envelope.
     *
     * @param  callable(): JsonResponse  $work
     */
    protected function runGuarded(callable $work): JsonResponse
    {
        try {
            return $work();
        } catch (AiQuotaExceededException $e) {
            return ApiResponse::error('AI_QUOTA_EXCEEDED', $e->getMessage(), ['scope' => $e->scope], 429);
        } catch (AiFeatureDisabledException $e) {
            return ApiResponse::error('AI_FEATURE_DISABLED', $e->getMessage(), ['reason' => $e->reason], 503);
        } catch (AiDisabledException $e) {
            return ApiResponse::error('AI_DISABLED', $e->getMessage(), [], 503);
        }
    }
}
