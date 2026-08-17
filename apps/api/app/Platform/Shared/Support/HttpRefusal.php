<?php

namespace App\Platform\Shared\Support;

/**
 * Stable error codes for refusals that carry no domain exception.
 *
 * A domain exception names WHY it refused (`LEARNING_ACCESS_EXPIRED`). A framework HTTP exception
 * only knows the status, so the best honest code is the status itself, named. That is worth doing
 * anyway: `HTTP_FORBIDDEN` is a code a client can switch on, and it never pretends to more
 * specificity than the thrower actually supplied.
 *
 * These names are part of the API contract — clients branch on them, so they do not get renamed.
 */
final class HttpRefusal
{
    /** @var array<int, string> */
    private const CODES = [
        400 => 'HTTP_BAD_REQUEST',
        401 => 'UNAUTHENTICATED',
        402 => 'HTTP_PAYMENT_REQUIRED',
        403 => 'HTTP_FORBIDDEN',
        404 => 'HTTP_NOT_FOUND',
        405 => 'HTTP_METHOD_NOT_ALLOWED',
        409 => 'HTTP_CONFLICT',
        410 => 'HTTP_GONE',
        413 => 'HTTP_PAYLOAD_TOO_LARGE',
        415 => 'HTTP_UNSUPPORTED_MEDIA_TYPE',
        422 => 'HTTP_UNPROCESSABLE',
        423 => 'HTTP_LOCKED',
        429 => 'HTTP_TOO_MANY_REQUESTS',
        503 => 'HTTP_SERVICE_UNAVAILABLE',
    ];

    /** @var array<int, string> */
    private const MESSAGES = [
        400 => 'The request could not be understood.',
        401 => 'Unauthenticated.',
        403 => 'You are not allowed to do that.',
        404 => 'Not found.',
        405 => 'That method is not allowed here.',
        409 => 'That conflicts with the current state.',
        410 => 'That is no longer available.',
        413 => 'That is too large.',
        415 => 'That media type is not supported.',
        422 => 'That request could not be processed.',
        429 => 'Too many requests. Try again shortly.',
        503 => 'The service is temporarily unavailable.',
    ];

    public static function codeFor(int $status): string
    {
        return self::CODES[$status] ?? ($status >= 500 ? 'HTTP_SERVER_ERROR' : 'HTTP_ERROR');
    }

    /** A message for a refusal the thrower gave none for. */
    public static function messageFor(int $status): string
    {
        return self::MESSAGES[$status] ?? 'The request was refused.';
    }
}
