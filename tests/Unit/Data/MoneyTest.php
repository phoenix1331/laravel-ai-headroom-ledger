<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroomLedger\Data\Money;
use Phoenix1331\AiHeadroomLedger\Exceptions\CurrencyMismatch;

it('holds an exact amount in micro-units', function (): void {
    $money = new Money(2_500, 'USD');

    expect($money->amount())->toBe(0.0025)
        ->and($money->estimated)->toBeFalse()
        ->and($money->approximate)->toBeFalse();
});

it('adds amounts in the same currency, carrying the flags', function (): void {
    $total = (new Money(1, 'GBP'))->plus(new Money(2, 'GBP', estimated: true))->plus(new Money(3, 'GBP', approximate: true));

    expect($total)
        ->microUnits->toBe(6)
        ->currency->toBe('GBP')
        ->estimated->toBeTrue()
        ->approximate->toBeTrue();
});

it('refuses to add different currencies', function (): void {
    (new Money(1, 'USD'))->plus(new Money(1, 'GBP'));
})->throws(CurrencyMismatch::class, 'Cannot combine USD and GBP');

it('starts from zero', function (): void {
    expect(Money::zero('EUR'))->microUnits->toBe(0)->currency->toBe('EUR');
});

it('rejects anything but a three-letter currency code', function (string $currency): void {
    new Money(1, $currency);
})->with(['usd', 'US', 'DOLLARS', ''])->throws(InvalidArgumentException::class, 'ISO 4217');

it('converts to an array', function (): void {
    expect((new Money(1_250_000, 'USD', estimated: true))->toArray())->toBe([
        'amount' => 1.25,
        'micro_units' => 1_250_000,
        'currency' => 'USD',
        'estimated' => true,
        'approximate' => false,
    ]);
});
