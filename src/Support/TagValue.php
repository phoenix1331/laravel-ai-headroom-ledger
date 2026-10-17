<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Support;

/**
 * How a scalar context tag value is stored and matched: as a string, with
 * booleans as "1" and "0".
 *
 * @internal
 */
final class TagValue
{
    public static function from(string|int|float|bool $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
}
