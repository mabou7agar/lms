<?php

namespace App\Logging;

use Illuminate\Support\Facades\Context;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Injects the current correlation id + service metadata into every log record so structured
 * (JSON) logs are traceable across the request lifecycle and the queue.
 */
class CorrelationProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['service'] = (string) config('app.name', 'helbaron');
        $record->extra['env'] = (string) config('app.env', 'production');

        // Prefer the propagated Context value (present in BOTH request and queue worker — M2), and
        // fall back to the inbound request header. Inside a worker request() is a synthetic console
        // request with no correlation header, which is exactly why the Context lookup comes first.
        $correlationId = Context::get('correlation_id') ?? request()?->headers?->get('X-Correlation-ID');
        if (is_string($correlationId) && $correlationId !== '') {
            $record->extra['correlation_id'] = $correlationId;
        }

        return $record;
    }
}
