<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Data;

use InvalidArgumentException;
use Phoenix1331\AiHeadroomLedger\Exceptions\CurrencyMismatch;

/**
 * An amount in one currency, held exactly as integer micro-units (millionths)
 * so sub-cent AI costs survive. Always says whether it is, or includes, an
 * estimate, and whether any part could not be priced exactly.
 */
final readonly class Money
{
    public const int MICRO_UNITS_PER_UNIT = 1_000_000;

    /**
     * @param  int  $microUnits  Millionths of the currency, e.g. 2_500 is 0.0025.
     * @param  string  $currency  ISO 4217 code, e.g. "USD".
     * @param  bool  $estimated  True when this is, or includes, a pre-flight estimate rather than reported usage.
     * @param  bool  $approximate  True when cache tokens were reported but could not be priced exactly.
     */
    public function __construct(
        public int $microUnits,
        public string $currency,
        public bool $estimated = false,
        public bool $approximate = false,
    ) {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("A currency must be a three-letter ISO 4217 code, \"{$currency}\" given.");
        }
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * The amount in whole currency units, e.g. 0.0025. Use microUnits for exact arithmetic.
     */
    public function amount(): float
    {
        return $this->microUnits / self::MICRO_UNITS_PER_UNIT;
    }

    /**
     * @throws CurrencyMismatch
     */
    public function plus(self $other): self
    {
        if ($other->currency !== $this->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }

        return new self(
            $this->microUnits + $other->microUnits,
            $this->currency,
            $this->estimated || $other->estimated,
            $this->approximate || $other->approximate,
        );
    }

    /**
     * @return array{amount: float, micro_units: int, currency: string, estimated: bool, approximate: bool}
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount(),
            'micro_units' => $this->microUnits,
            'currency' => $this->currency,
            'estimated' => $this->estimated,
            'approximate' => $this->approximate,
        ];
    }
}
