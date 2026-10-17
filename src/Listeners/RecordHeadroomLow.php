<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Listeners;

use Phoenix1331\AiHeadroom\Events\HeadroomLow;

/**
 * Records a request allowed through despite a breached limit.
 *
 * @extends RecordsToLedger<HeadroomLow>
 */
final class RecordHeadroomLow extends RecordsToLedger
{
    protected function record(object $event): void
    {
        $this->recorder->warned($event);
    }

    protected function requestId(object $event): string
    {
        return $event->requestId;
    }
}
