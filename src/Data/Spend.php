<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Data;

use Phoenix1331\AiHeadroomLedger\Exceptions\CurrencyMismatch;

/**
 * Money grouped by currency. Amounts in different currencies are kept apart,
 * never summed or converted.
 */
final readonly class Spend
{
    /** @var array<string, Money> */
    private array $byCurrency;

    /**
     * @param  iterable<Money>  $amounts
     */
    public function __construct(iterable $amounts = [])
    {
        $byCurrency = [];

        foreach ($amounts as $money) {
            $byCurrency[$money->currency] = isset($byCurrency[$money->currency])
                ? $byCurrency[$money->currency]->plus($money)
                : $money;
        }

        ksort($byCurrency);

        $this->byCurrency = $byCurrency;
    }

    public function in(string $currency): ?Money
    {
        return $this->byCurrency[$currency] ?? null;
    }

    /**
     * The only currency's total, for the usual single-currency app.
     *
     * @throws CurrencyMismatch When there is no spend, or spend in more than one currency.
     */
    public function sole(): Money
    {
        if (count($this->byCurrency) !== 1) {
            throw CurrencyMismatch::expectedOne($this->currencies());
        }

        return $this->byCurrency[array_key_first($this->byCurrency)];
    }

    /**
     * @return list<string>
     */
    public function currencies(): array
    {
        return array_keys($this->byCurrency);
    }

    /**
     * @return list<Money>
     */
    public function all(): array
    {
        return array_values($this->byCurrency);
    }

    public function isEmpty(): bool
    {
        return $this->byCurrency === [];
    }

    /**
     * True when any part of the spend is an estimate rather than reported usage.
     */
    public function isEstimated(): bool
    {
        foreach ($this->byCurrency as $money) {
            if ($money->estimated) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{amount: float, micro_units: int, currency: string, estimated: bool, approximate: bool}>
     */
    public function toArray(): array
    {
        return array_map(fn (Money $money): array => $money->toArray(), $this->all());
    }
}
