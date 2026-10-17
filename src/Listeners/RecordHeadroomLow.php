<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Listeners;

use Phoenix1331\AiHeadroom\Events\HeadroomLow;
use Phoenix1331\AiHeadroomLedger\Recording\EntryRecorder;

final class RecordHeadroomLow
{
    public function __construct(private readonly EntryRecorder $recorder) {}

    public function handle(HeadroomLow $event): void
    {
        $this->recorder->warned($event);
    }
}
