<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroomLedger\Support\MicroUnits;

it('converts amounts to micro-units', function (float $amount, int $expected): void {
    expect(MicroUnits::fromAmount($amount))->toBe($expected);
})->with([
    'a whole unit' => [1.0, 1_000_000],
    'a fraction of a cent' => [0.000312, 312],
    'zero' => [0.0, 0],
    'rounds half up' => [0.0000005, 1],
    'rounds down below half' => [0.0000004, 0],
    'float noise' => [0.1 + 0.2, 300_000],
]);

it('converts micro-units back to amounts', function (): void {
    expect(MicroUnits::toAmount(312))->toBe(0.000312)
        ->and(MicroUnits::toAmount(2_500_000))->toBe(2.5);
});
