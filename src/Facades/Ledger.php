<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Facades;

use Illuminate\Support\Facades\Facade;
use Phoenix1331\AiHeadroomLedger\LedgerManager;

/**
 * @method static \Phoenix1331\AiHeadroomLedger\Query\LedgerQuery query()
 * @method static \Phoenix1331\AiHeadroomLedger\Data\LedgerEntry|null find(string $requestId)
 * @method static \Phoenix1331\AiHeadroomLedger\Data\RunSummary|null run(string $invocationId)
 * @method static int forgetTag(string $key, string|int|float|bool $value)
 *
 * @see LedgerManager
 */
final class Ledger extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LedgerManager::class;
    }
}
