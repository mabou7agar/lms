<?php

declare(strict_types=1);

namespace App\Platform\AI\Exceptions;

use RuntimeException;

/**
 * Base type for every AI-foundation failure. Callers can catch this to fail closed around any AI
 * operation without depending on the specific reason.
 */
class AiException extends RuntimeException {}
