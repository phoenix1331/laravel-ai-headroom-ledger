<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroomLedger\Data\LedgerEntries;
use Phoenix1331\AiHeadroomLedger\Data\LedgerEntry;
use Phoenix1331\AiHeadroomLedger\Data\Money;

it('is an empty, countable, iterable list by default', function (): void {
    $entries = new LedgerEntries;

    expect($entries->isEmpty())->toBeTrue()
        ->and($entries)->toHaveCount(0)
        ->and($entries->first())->toBeNull()
        ->and($entries->last())->toBeNull()
        ->and(iterator_to_array($entries))->toBe([]);
});

it('keeps entries in order', function (): void {
    $entries = new LedgerEntries((function () {
        yield entryVo('a');
        yield entryVo('b');
    })());

    expect($entries->first()?->requestId)->toBe('a')
        ->and($entries->last()?->requestId)->toBe('b')
        ->and(array_map(fn (LedgerEntry $e): string => $e->requestId, $entries->all()))->toBe(['a', 'b'])
        ->and($entries->collect()->pluck('requestId')->all())->toBe(['a', 'b']);
});

it('totals tokens and spend, using estimates where usage is missing', function (): void {
    $entries = new LedgerEntries([
        entryVo('a', new Money(300, 'USD', estimated: true), new Money(250, 'USD'), input: 120),
        entryVo('b', new Money(400, 'USD', estimated: true)),
        entryVo('c'),
    ]);

    expect($entries->tokens()->estimatedInput)->toBe(300)
        ->and($entries->tokens()->input)->toBe(120)
        ->and($entries->spend()->sole())
        ->microUnits->toBe(650)
        ->estimated->toBeTrue();
});
