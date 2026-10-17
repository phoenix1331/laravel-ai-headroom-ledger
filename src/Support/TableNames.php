<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Support;

/**
 * Ledger table names and connection, read from config.
 *
 * @internal
 */
final class TableNames
{
    public const string DEFAULT_PREFIX = 'headroom_ledger_';

    public static function entries(): string
    {
        return self::prefix().'entries';
    }

    public static function tags(): string
    {
        return self::prefix().'tags';
    }

    public static function daily(): string
    {
        return self::prefix().'daily';
    }

    public static function connection(): ?string
    {
        $connection = config('headroom-ledger.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private static function prefix(): string
    {
        $prefix = config('headroom-ledger.table_prefix');

        return is_string($prefix) ? $prefix : self::DEFAULT_PREFIX;
    }
}
