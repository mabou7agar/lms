<?php

namespace App\Platform\Shared\Analytics\Contracts;

use App\Platform\Shared\Analytics\Data\AnalyticsEventInput;

/**
 * "Write this down." DECLARED in Shared, IMPLEMENTED by Analytics, called from wherever the thing
 * actually happened.
 *
 * THE IMPLEMENTATION MUST NEVER THROW. Recording an event is a reporting concern, and a reporting
 * concern must not be able to fail a purchase, a download or an answer. A recorder that cannot
 * write swallows the problem and logs it; the business flow it was called from carries on as if
 * analytics did not exist — because from the user's point of view it does not.
 *
 * That guarantee is why this is a port rather than a direct model write: a caller can invoke it
 * without a try/catch and without thinking about it, which is the only way instrumentation actually
 * ends up in the code paths that matter.
 */
interface AnalyticsEventRecorder
{
    public function record(AnalyticsEventInput $event): void;

    /**
     * Record several at once — a fulfilment touching five courses is five events, and five separate
     * inserts inside one request is five chances to slow it down.
     *
     * @param  list<AnalyticsEventInput>  $events
     */
    public function recordMany(array $events): void;
}
