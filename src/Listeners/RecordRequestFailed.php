<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Listeners;

use Phoenix1331\AiHeadroom\Events\RequestFailed;

/**
 * Records a provider call that threw.
 *
 * @extends RecordsToLedger<RequestFailed>
 */
final class RecordRequestFailed extends RecordsToLedger
{
    protected function record(object $event): void
    {
        $this->recorder->failed($event);
    }

    protected function requestId(object $event): string
    {
        return $event->requestId;
    }
}
