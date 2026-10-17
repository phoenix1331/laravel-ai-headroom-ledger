<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroomLedger\Enums\Outcome;

it('stores the outcome values from the brief', function (): void {
    expect(array_map(fn (Outcome $outcome): string => $outcome->value, Outcome::cases()))
        ->toBe(['counted', 'completed', 'warned', 'blocked', 'failed']);
});
