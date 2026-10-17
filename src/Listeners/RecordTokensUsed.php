<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Listeners;

use Phoenix1331\AiHeadroom\Events\TokensUsed;
use Phoenix1331\AiHeadroomLedger\Recording\EntryRecorder;

final class RecordTokensUsed
{
    public function __construct(private readonly EntryRecorder $recorder) {}

    public function handle(TokensUsed $event): void
    {
        $this->recorder->used($event);
    }
}
