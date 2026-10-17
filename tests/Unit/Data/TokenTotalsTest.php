<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroomLedger\Data\TokenTotals;

it('adds totals and reports cached input', function (): void {
    $total = (new TokenTotals(100, 110, 20, 50, 5))->plus(new TokenTotals(1, 2, 3, 4, 5));

    expect($total->toArray())->toBe(['estimated_input' => 101, 'input' => 112, 'output' => 23, 'cache_read' => 54, 'cache_write' => 10])
        ->and($total->cached())->toBe(54);
});
