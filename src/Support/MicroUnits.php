<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Support;

/**
 * Converts between currency amounts and the integer micro-units (millionths)
 * the ledger stores, so sub-cent costs survive and sums stay exact.
 *
 * @internal
 */
final class MicroUnits
{
    public const int PER_UNIT = 1_000_000;

    public static function fromAmount(float $amount): int
    {
        return (int) round($amount * self::PER_UNIT);
    }

    public static function toAmount(int $microUnits): float
    {
        return $microUnits / self::PER_UNIT;
    }
}
