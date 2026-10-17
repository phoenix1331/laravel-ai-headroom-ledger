<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Listeners;

use Phoenix1331\AiHeadroom\Events\RequestBlocked;

/**
 * Records a request blocked before sending.
 *
 * @extends RecordsToLedger<RequestBlocked>
 */
final class RecordRequestBlocked extends RecordsToLedger
{
    protected function record(object $event): void
    {
        $this->recorder->blocked($event);
    }

    protected function requestId(object $event): string
    {
        return $event->requestId;
    }
}
