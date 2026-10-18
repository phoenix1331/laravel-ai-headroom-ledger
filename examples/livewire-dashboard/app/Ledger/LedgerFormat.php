<?php

declare(strict_types=1);

namespace App\Ledger;

use Phoenix1331\AiHeadroomLedger\Data\Money;
use Phoenix1331\AiHeadroomLedger\Data\Spend;

/**
 * Display helpers for ledger value objects. Formatting only; every figure comes from the query API.
 */
final class LedgerFormat
{
    /**
     * Amounts to 6 decimals, trimmed to at least 2, so a fraction of a cent stays visible.
     */
    public static function money(?Money $money): string
    {
        if ($money === null) {
            return '-';
        }

        $amount = rtrim(number_format($money->amount(), 6, '.', ','), '0');
        $decimals = strlen((string) strrchr($amount, '.')) - 1;

        if ($decimals < 2) {
            $amount .= str_repeat('0', 2 - $decimals);
        }

        return $money->currency.' '.$amount;
    }

    /**
     * Each currency on its own; currencies are never added together.
     */
    public static function spend(Spend $spend): string
    {
        if ($spend->isEmpty()) {
            return '-';
        }

        return implode(' + ', array_map(self::money(...), $spend->all()));
    }

    public static function tokens(?int $tokens): string
    {
        if ($tokens === null) {
            return '-';
        }

        if ($tokens >= 1_000_000) {
            return round($tokens / 1_000_000, 1).'M';
        }

        if ($tokens >= 10_000) {
            return round($tokens / 1_000).'K';
        }

        return number_format($tokens);
    }

    public static function percent(?float $percent, int $decimals = 1): string
    {
        return $percent === null ? '-' : number_format($percent, $decimals).'%';
    }

    /**
     * Agents are stored by class name; the basename reads better in a table.
     */
    public static function agent(?string $agent): string
    {
        return $agent === null ? '-' : class_basename($agent);
    }
}
