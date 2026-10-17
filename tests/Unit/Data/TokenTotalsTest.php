<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroomLedger\Data\TokenTotals;

it('adds totals and reports cached input', function (): void {
    $total = (new TokenTotals(100, 110, 20, 50, 5, 3, 2))->plus(new TokenTotals(1, 2, 3, 4, 5, 1, 1));

    expect($total->toArray())->toBe(['estimated_input' => 101, 'input' => 112, 'output' => 23, 'cache_read' => 54, 'cache_write' => 10, 'requests' => 4, 'requests_with_usage' => 3])
        ->and($total->cached())->toBe(54);
});

it('counts one request per entry, with usage only once reported', function (): void {
    expect(entryVo('a', input: 10)->tokens())->requests->toBe(1)->requestsWithUsage->toBe(0);
});
