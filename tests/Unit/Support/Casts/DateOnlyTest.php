<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Support\Casts\DateOnly;

it('stores dates without a time', function (mixed $value): void {
    expect((new DateOnly)->set(new Entry, 'occurred_on', $value, []))->toBe('2026-10-17');
})->with([
    'date string' => ['2026-10-17'],
    'datetime string' => ['2026-10-17 23:59:59'],
    'carbon' => [CarbonImmutable::parse('2026-10-17 09:30:00')],
    'native datetime' => [new DateTime('2026-10-17 09:30:00')],
]);

it('stores null', function (): void {
    expect((new DateOnly)->set(new Entry, 'occurred_on', null, []))->toBeNull();
});

it('rejects anything else', function (): void {
    (new DateOnly)->set(new Entry, 'occurred_on', 20261017, []);
})->throws(InvalidArgumentException::class);

it('reads a stored date, or a datetime a database returned, as midnight', function (string $stored): void {
    expect((new DateOnly)->get(new Entry, 'occurred_on', $stored, []))
        ->toEqual(CarbonImmutable::parse('2026-10-17 00:00:00'));
})->with(['2026-10-17', '2026-10-17 00:00:00']);

it('reads null', function (): void {
    expect((new DateOnly)->get(new Entry, 'occurred_on', null, []))->toBeNull();
});
