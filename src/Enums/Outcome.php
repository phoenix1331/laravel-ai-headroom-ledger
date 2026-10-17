<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Enums;

/**
 * What happened to a request, as far as the ledger knows.
 */
enum Outcome: string
{
    /** Headroom counted it; no usage has been reported (yet). */
    case Counted = 'counted';

    /** The provider answered and usage was recorded. */
    case Completed = 'completed';

    /** A soft limit was crossed (HeadroomLow); the request was allowed. */
    case Warned = 'warned';

    /** A hard limit was crossed (RequestBlocked); the request was never sent. */
    case Blocked = 'blocked';

    /** The provider call failed (RequestFailed). */
    case Failed = 'failed';
}
