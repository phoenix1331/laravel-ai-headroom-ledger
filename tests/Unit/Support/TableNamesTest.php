<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroomLedger\Support\TableNames;

it('uses the default prefix', function (): void {
    expect(TableNames::entries())->toBe('headroom_ledger_entries')
        ->and(TableNames::tags())->toBe('headroom_ledger_tags')
        ->and(TableNames::daily())->toBe('headroom_ledger_daily');
});

it('uses the configured prefix', function (): void {
    config(['headroom-ledger.table_prefix' => 'ai_']);

    expect(TableNames::entries())->toBe('ai_entries');
});

it('allows an empty prefix', function (): void {
    config(['headroom-ledger.table_prefix' => '']);

    expect(TableNames::tags())->toBe('tags');
});

it('falls back to the default prefix when the config is not a string', function (): void {
    config(['headroom-ledger.table_prefix' => null]);

    expect(TableNames::daily())->toBe('headroom_ledger_daily');
});

it('returns the configured connection, or null for the default', function (?string $configured, ?string $expected): void {
    config([
        'database.connections.ledger' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'headroom-ledger.connection' => $configured,
    ]);

    expect(TableNames::connection())->toBe($expected);
})->with([
    'unset' => [null, null],
    'empty string' => ['', null],
    'named' => ['ledger', 'ledger'],
]);
