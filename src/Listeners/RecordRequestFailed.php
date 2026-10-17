<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Listeners;

use Phoenix1331\AiHeadroom\Events\RequestFailed;
use Phoenix1331\AiHeadroomLedger\Recording\EntryRecorder;

final class RecordRequestFailed
{
    public function __construct(private readonly EntryRecorder $recorder) {}

    public function handle(RequestFailed $event): void
    {
        $this->recorder->failed($event);
    }
}
