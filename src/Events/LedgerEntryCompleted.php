<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Events;

use Phoenix1331\AiHeadroomLedger\Enums\Outcome;

/**
 * The provider's usage for a request is now in the ledger, so its actual
 * tokens and cost are known. $outcome is Completed, or Warned for a request
 * that crossed a soft limit. Fires once per TokensUsed. Public API covered
 * by SemVer.
 */
final readonly class LedgerEntryCompleted
{
    public function __construct(
        public string $requestId,
        public ?string $invocationId,
        public Outcome $outcome,
    ) {}
}
