<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures every request carries a correlation id. Reuses an inbound X-Correlation-ID (from an
 * upstream gateway) or generates a UUIDv4. Pushed into the log context and echoed on the
 * response so it lines up with the error-envelope correlation_id already returned by ApiResponse.
 */
class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $correlationId = is_string($incoming) && trim($incoming) !== '' ? trim($incoming) : (string) Str::uuid();

        // Make it visible to ApiResponse::correlationId() and downstream code.
        $request->headers->set(self::HEADER, $correlationId);
        Log::withContext(['correlation_id' => $correlationId]);

        // M2 — propagate into queued work. Laravel's Context is serialized into each job's payload
        // and rehydrated in the worker, so a job dispatched during this request keeps this
        // correlation id in its log lines (CorrelationProcessor reads it back). This is the fix for
        // correlation ids never reaching async delivery.
        Context::add('correlation_id', $correlationId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
