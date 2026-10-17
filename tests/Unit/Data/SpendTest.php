<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroomLedger\Data\Money;
use Phoenix1331\AiHeadroomLedger\Data\Spend;
use Phoenix1331\AiHeadroomLedger\Exceptions\CurrencyMismatch;

it('sums per currency and never across currencies', function (): void {
    $spend = new Spend([new Money(1, 'USD'), new Money(10, 'GBP'), new Money(2, 'USD')]);

    expect($spend->currencies())->toBe(['GBP', 'USD'])
        ->and($spend->in('USD')?->microUnits)->toBe(3)
        ->and($spend->in('GBP')?->microUnits)->toBe(10)
        ->and($spend->in('EUR'))->toBeNull()
        ->and($spend->all())->toHaveCount(2);
});

it('returns the sole currency total', function (): void {
    expect((new Spend([new Money(1, 'USD'), new Money(2, 'USD')]))->sole()->microUnits)->toBe(3);
});

it('refuses a sole total across currencies', function (): void {
    (new Spend([new Money(1, 'USD'), new Money(1, 'GBP')]))->sole();
})->throws(CurrencyMismatch::class, 'several currencies (GBP, USD)');

it('refuses a sole total when there is no spend', function (): void {
    (new Spend)->sole();
})->throws(CurrencyMismatch::class, 'no spend');

it('knows when it is empty or includes estimates', function (): void {
    expect((new Spend)->isEmpty())->toBeTrue()
        ->and((new Spend([new Money(1, 'USD')]))->isEstimated())->toBeFalse()
        ->and((new Spend([new Money(1, 'USD'), new Money(1, 'GBP', estimated: true)]))->isEstimated())->toBeTrue();
});

it('converts to an array', function (): void {
    expect((new Spend([new Money(1, 'USD')]))->toArray())
        ->toBe([['amount' => 0.000001, 'micro_units' => 1, 'currency' => 'USD', 'estimated' => false, 'approximate' => false]]);
});
