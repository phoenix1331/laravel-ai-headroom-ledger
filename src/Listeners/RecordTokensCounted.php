<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Listeners;

use Phoenix1331\AiHeadroom\Events\TokensCounted;
use Phoenix1331\AiHeadroomLedger\Recording\EntryRecorder;

final class RecordTokensCounted
{
    public function __construct(private readonly EntryRecorder $recorder) {}

    public function handle(TokensCounted $event): void
    {
        $this->recorder->counted($event);
    }
}
