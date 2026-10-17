<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Listeners;

use Phoenix1331\AiHeadroom\Events\RequestBlocked;
use Phoenix1331\AiHeadroomLedger\Recording\EntryRecorder;

final class RecordRequestBlocked
{
    public function __construct(private readonly EntryRecorder $recorder) {}

    public function handle(RequestBlocked $event): void
    {
        $this->recorder->blocked($event);
    }
}
