<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Listeners;

use Phoenix1331\AiHeadroom\Events\TokensUsed;

/**
 * Records the provider's reported usage and actual cost.
 *
 * @extends RecordsToLedger<TokensUsed>
 */
final class RecordTokensUsed extends RecordsToLedger
{
    protected function record(object $event): void
    {
        $this->recorder->used($event);
    }

    protected function requestId(object $event): string
    {
        return $event->requestId;
    }

    protected function completes(): bool
    {
        return true;
    }
}
