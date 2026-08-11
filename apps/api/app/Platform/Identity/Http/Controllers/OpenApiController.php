<?php

namespace App\Platform\Identity\Http\Controllers;

use App\Platform\Identity\Services\OpenApiSpecGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Serves the public developer-API OpenAPI 3.1 document at GET /api/openapi.json. Unauthenticated:
 * the document is descriptive metadata (it exposes no account data), so developers can read it
 * before they hold a key.
 */
class OpenApiController extends Controller
{
    public function show(OpenApiSpecGenerator $generator): JsonResponse
    {
        return response()->json(
            $generator->generate(),
            200,
            [],
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );
    }
}
