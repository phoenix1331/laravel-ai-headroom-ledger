<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Exceptions;

use InvalidArgumentException;

/**
 * Amounts in different currencies were combined. The ledger never converts
 * currencies; group by currency instead.
 */
final class CurrencyMismatch extends InvalidArgumentException
{
    public static function between(string $a, string $b): self
    {
        return new self("Cannot combine {$a} and {$b} amounts; the ledger never converts currencies.");
    }

    /**
     * @param  list<string>  $currencies
     */
    public static function expectedOne(array $currencies): self
    {
        return new self($currencies === []
            ? 'There is no spend to return.'
            : 'Spend is in several currencies ('.implode(', ', $currencies).'); read each with in().');
    }
}
