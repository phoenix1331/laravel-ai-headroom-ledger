<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Phoenix1331\AiHeadroomLedger\AiHeadroomLedgerServiceProvider;

it('boots alongside headroom', function (): void {
    expect(app()->getProvider(AiHeadroomLedgerServiceProvider::class))->not->toBeNull();
});

it('merges the default config', function (): void {
    expect(config('headroom-ledger'))
        ->enabled->toBeTrue()
        ->connection->toBeNull()
        ->table_prefix->toBe('headroom_ledger_')
        ->and(config('headroom-ledger.tags.allowed'))->toBe([])
        ->and(config('headroom-ledger.tags.primary_tag'))->toBeNull();
});

it('registers the config and migration publish tags', function (string $tag): void {
    expect(ServiceProvider::pathsToPublish(AiHeadroomLedgerServiceProvider::class, $tag))->not->toBeEmpty();
})->with(['headroom-ledger-config', 'headroom-ledger-migrations']);
