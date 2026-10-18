<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Enums;

/**
 * What LedgerQuery::orderBy() sorts entries by. Requests without the figure
 * always sort last, in either direction.
 */
enum EntrySort: string
{
    /** When the request was counted, else when its usage was reported. */
    case CountedAt = 'counted_at';

    /** Share of the context window the estimate used. */
    case PercentUsed = 'percent_used';

    /**
     * Actual cost where usage was reported, else the estimate. Amounts sort
     * by micro-units, so mixed currencies are not converted first.
     */
    case Cost = 'cost';

    /** Reported input tokens, else the estimate. */
    case InputTokens = 'input_tokens';

    /** Reported output tokens. */
    case OutputTokens = 'output_tokens';
}
