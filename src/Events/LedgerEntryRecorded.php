<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Events;

use Phoenix1331\AiHeadroomLedger\Enums\Outcome;

/**
 * A Headroom event was written to the ledger. Fires for every write, so one
 * request usually fires it several times; $outcome is the outcome after this
 * write. Public API covered by SemVer.
 */
final readonly class LedgerEntryRecorded
{
    public function __construct(
        public string $requestId,
        public ?string $invocationId,
        public Outcome $outcome,
    ) {}
}
