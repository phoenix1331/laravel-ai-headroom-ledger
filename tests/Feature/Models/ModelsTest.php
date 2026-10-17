<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroomLedger\Enums\Outcome;
use Phoenix1331\AiHeadroomLedger\Models\DailyRollup;
use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Models\Tag;

it('round-trips an entry through its casts', function (): void {
    Entry::query()->create([
        'request_id' => '01J0000000000000000000000A',
        'invocation_id' => '0199f3a2-0000-7000-8000-000000000001',
        'agent' => 'App\\Ai\\SupportAgent',
        'count_method' => CountMethod::Calibrated,
        'estimated_input_tokens' => 1200,
        'breakdown' => ['instructions' => 400, 'history' => 600, 'tools' => 0, 'attachments' => 0, 'prompt' => 200],
        'context_window' => 200_000,
        'percent_used' => 0.6,
        'currency' => 'USD',
        'estimated_cost' => 3600,
        'occurred_on' => '2026-10-17',
        'counted_at' => '2026-10-17 09:30:00',
    ]);

    $entry = Entry::query()->findOrFail('01J0000000000000000000000A');

    expect($entry)
        ->count_method->toBe(CountMethod::Calibrated)
        ->outcome->toBe(Outcome::Counted)
        ->breakdown->toBe(['instructions' => 400, 'history' => 600, 'tools' => 0, 'attachments' => 0, 'prompt' => 200])
        ->percent_used->toBe(0.6)
        ->estimated_cost->toBe(3600)
        ->actual_cost->toBeNull()
        ->cost_approximate->toBeFalse()
        ->streamed->toBeFalse()
        ->occurred_on->toBeInstanceOf(CarbonImmutable::class)
        ->counted_at->toEqual(CarbonImmutable::parse('2026-10-17 09:30:00'))
        ->used_at->toBeNull();
});

it('defaults a new entry to counted before it is saved', function (): void {
    expect((new Entry)->outcome)->toBe(Outcome::Counted);
});

it('stores the outcome enum by value', function (): void {
    Entry::query()->create(['request_id' => 'a', 'occurred_on' => '2026-10-17', 'outcome' => Outcome::Blocked]);

    expect(Entry::query()->where('outcome', 'blocked')->count())->toBe(1);
});

it('links tags to their entry both ways', function (): void {
    $entry = Entry::query()->create(['request_id' => 'a', 'occurred_on' => '2026-10-17']);
    $entry->tags()->create(['key' => 'tenant_id', 'value' => '42']);

    $tag = Tag::query()->firstOrFail();

    expect($entry->tags()->pluck('value')->all())->toBe(['42'])
        ->and($tag->entry?->request_id)->toBe('a');
});

it('round-trips a daily rollup through its casts', function (): void {
    DailyRollup::query()->create([
        'date' => '2026-10-16',
        'model' => 'claude-sonnet-5-5',
        'count_method' => CountMethod::Native,
        'currency' => 'GBP',
        'requests' => 3,
        'actual_cost' => 9_000,
        'percent_used_sum' => 120.5,
        'percent_used_max' => 81.25,
    ]);

    $rollup = DailyRollup::query()->firstOrFail();

    expect($rollup)
        ->date->toEqual(CarbonImmutable::parse('2026-10-16'))
        ->count_method->toBe(CountMethod::Native)
        ->requests->toBe(3)
        ->actual_cost->toBe(9_000)
        ->percent_used_sum->toBe(120.5)
        ->percent_used_max->toBe(81.25)
        ->blocked->toBe(0);
});

it('reads and writes prefixed tables', function (): void {
    config(['headroom-ledger.table_prefix' => 'ai_']);

    expect((new Entry)->getTable())->toBe('ai_entries')
        ->and((new Tag)->getTable())->toBe('ai_tags')
        ->and((new DailyRollup)->getTable())->toBe('ai_daily');
});

it('uses the configured connection', function (): void {
    config([
        'database.connections.ledger' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        'headroom-ledger.connection' => 'ledger',
    ]);

    Schema::connection('ledger')->create('headroom_ledger_entries', function ($table): void {
        $table->string('request_id')->primary();
        $table->date('occurred_on');
        $table->string('outcome')->default('counted');
        $table->boolean('cost_approximate')->default(false);
        $table->boolean('streamed')->default(false);
    });

    Entry::query()->create(['request_id' => 'a', 'occurred_on' => '2026-10-17']);

    expect((new Entry)->getConnectionName())->toBe('ledger')
        ->and(Entry::query()->count())->toBe(1)
        ->and(Entry::on('testing')->count())->toBe(0);
});

it('uses the default connection when none is configured', function (): void {
    expect((new Entry)->getConnectionName())->toBeNull();
});
