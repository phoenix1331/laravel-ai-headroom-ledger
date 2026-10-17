<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Support;

use Carbon\CarbonImmutable;

/**
 * Retention cut-offs from config, as "Y-m-d": data from days before the
 * cut-off is pruned. Null when the data is kept forever.
 *
 * @internal
 */
final class Retention
{
    public static function entriesCutoff(): ?string
    {
        return self::cutoff('headroom-ledger.retention_days.entries');
    }

    public static function rollupsCutoff(): ?string
    {
        return self::cutoff('headroom-ledger.retention_days.rollups');
    }

    private static function cutoff(string $key): ?string
    {
        $days = config($key);

        if (! is_numeric($days) || (int) $days < 1) {
            return null;
        }

        return CarbonImmutable::today()->subDays((int) $days)->toDateString();
    }
}
