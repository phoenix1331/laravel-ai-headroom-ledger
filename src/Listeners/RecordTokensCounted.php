<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Listeners;

use Phoenix1331\AiHeadroom\Events\TokensCounted;

/**
 * Records the pre-flight count.
 *
 * @extends RecordsToLedger<TokensCounted>
 */
final class RecordTokensCounted extends RecordsToLedger
{
    protected function record(object $event): void
    {
        $this->recorder->counted($event);
    }

    protected function requestId(object $event): string
    {
        return $event->requestId;
    }
}
