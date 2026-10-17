<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function ledgerMigration(): Migration
{
    $files = glob(__DIR__.'/../../../database/migrations/*_create_headroom_ledger_tables.php') ?: [];

    return require $files[0];
}

function ledgerEntry(string $requestId, array $overrides = []): array
{
    return array_merge([
        'request_id' => $requestId,
        'occurred_on' => '2026-10-17',
    ], $overrides);
}

it('creates the entries table with every column', function (): void {
    expect(Schema::hasColumns('headroom_ledger_entries', [
        'request_id', 'invocation_id', 'agent',
        'provider_counted', 'model_counted', 'provider_used', 'model_used', 'count_method',
        'estimated_input_tokens', 'breakdown', 'context_window', 'percent_used',
        'actual_input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens',
        'currency', 'estimated_cost', 'actual_cost', 'cost_approximate',
        'streamed', 'outcome', 'outcome_reason',
        'occurred_on', 'counted_at', 'used_at',
    ]))->toBeTrue();
});

it('creates the tags and daily tables', function (): void {
    expect(Schema::hasColumns('headroom_ledger_tags', ['request_id', 'key', 'value']))->toBeTrue()
        ->and(Schema::hasColumns('headroom_ledger_daily', [
            'date', 'agent', 'provider', 'model', 'count_method', 'currency',
            'primary_tag_key', 'primary_tag_value',
            'requests', 'requests_with_usage', 'priced_requests', 'completed', 'warned', 'blocked', 'failed', 'streamed',
            'estimated_input_tokens', 'actual_input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens',
            'estimated_cost', 'actual_cost', 'estimated_only_cost', 'estimated_only_requests', 'approximate_requests',
            'context_samples', 'percent_used_sum', 'percent_used_max',
            'accuracy_samples', 'accuracy_estimated_tokens', 'accuracy_actual_tokens', 'accuracy_absolute_error',
        ]))->toBeTrue();
});

it('defaults a new entry to counted, not streamed and not approximate', function (): void {
    DB::table('headroom_ledger_entries')->insert(ledgerEntry('01J0000000000000000000000A'));

    $row = DB::table('headroom_ledger_entries')->first();

    expect($row)
        ->outcome->toBe('counted')
        ->and((bool) $row?->streamed)->toBeFalse()
        ->and((bool) $row?->cost_approximate)->toBeFalse();
});

it('stores sub-cent costs exactly as micro-units', function (): void {
    DB::table('headroom_ledger_entries')->insert(ledgerEntry('01J0000000000000000000000A', [
        'currency' => 'USD',
        'estimated_cost' => 312,
        'actual_cost' => 287,
    ]));
    DB::table('headroom_ledger_entries')->insert(ledgerEntry('01J0000000000000000000000B', [
        'currency' => 'USD',
        'actual_cost' => 1,
    ]));

    expect((int) DB::table('headroom_ledger_entries')->sum('actual_cost'))->toBe(288);
});

it('rejects a second tag with the same key on one entry', function (): void {
    DB::table('headroom_ledger_entries')->insert(ledgerEntry('01J0000000000000000000000A'));
    DB::table('headroom_ledger_tags')->insert(['request_id' => '01J0000000000000000000000A', 'key' => 'tenant_id', 'value' => '42']);

    DB::table('headroom_ledger_tags')->insert(['request_id' => '01J0000000000000000000000A', 'key' => 'tenant_id', 'value' => '43']);
})->throws(UniqueConstraintViolationException::class);

it('deletes tags with their entry', function (): void {
    DB::table('headroom_ledger_entries')->insert(ledgerEntry('01J0000000000000000000000A'));
    DB::table('headroom_ledger_tags')->insert(['request_id' => '01J0000000000000000000000A', 'key' => 'tenant_id', 'value' => '42']);

    DB::table('headroom_ledger_entries')->where('request_id', '01J0000000000000000000000A')->delete();

    expect(DB::table('headroom_ledger_tags')->count())->toBe(0);
});

it('drops every table on rollback', function (): void {
    ledgerMigration()->down();

    expect(Schema::hasTable('headroom_ledger_entries'))->toBeFalse()
        ->and(Schema::hasTable('headroom_ledger_tags'))->toBeFalse()
        ->and(Schema::hasTable('headroom_ledger_daily'))->toBeFalse();
});

it('honours the table prefix', function (): void {
    config(['headroom-ledger.table_prefix' => 'ai_usage_']);

    $migration = ledgerMigration();
    $migration->up();

    expect(Schema::hasTable('ai_usage_entries'))->toBeTrue()
        ->and(Schema::hasTable('ai_usage_tags'))->toBeTrue()
        ->and(Schema::hasTable('ai_usage_daily'))->toBeTrue();

    $migration->down();

    expect(Schema::hasTable('ai_usage_entries'))->toBeFalse();
});

it('falls back to the default prefix when the configured one is not a string', function (): void {
    config(['headroom-ledger.table_prefix' => null]);

    $migration = ledgerMigration();
    $migration->down();

    expect(Schema::hasTable('headroom_ledger_entries'))->toBeFalse();
});

it('runs on a separate connection when one is configured', function (): void {
    config([
        'database.connections.ledger' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        'headroom-ledger.connection' => 'ledger',
        'headroom-ledger.table_prefix' => 'separate_',
    ]);

    $migration = ledgerMigration();

    expect($migration->getConnection())->toBe('ledger');

    // The suite has already run this migration on the default connection; forget it so it runs again.
    DB::table('migrations')->delete();
    $this->app->make('migrator')->run([__DIR__.'/../../../database/migrations']);

    expect(Schema::connection('ledger')->hasTable('separate_entries'))->toBeTrue()
        ->and(Schema::hasTable('separate_entries'))->toBeFalse();
});

it('uses the default connection when none is configured', function (): void {
    expect(ledgerMigration()->getConnection())->toBeNull();
});
