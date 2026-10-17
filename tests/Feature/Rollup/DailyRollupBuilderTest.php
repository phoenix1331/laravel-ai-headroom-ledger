<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroomLedger\Database\Factories\LedgerEntryFactory;
use Phoenix1331\AiHeadroomLedger\Facades\Ledger;
use Phoenix1331\AiHeadroomLedger\Models\DailyRollup;
use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Rollup\DailyRollupBuilder;

function on(string $day): LedgerEntryFactory
{
    return LedgerEntryFactory::new()->at(CarbonImmutable::parse($day.' 10:00:00'));
}

/**
 * Entries that all land in the same rollup group.
 */
function sameGroup(string $day = '2026-10-16'): LedgerEntryFactory
{
    return on($day)->forModel('claude-sonnet-5-5')->forAgent('App\\Ai\\Agents\\SupportAgent')->countMethod(CountMethod::Estimated);
}

/**
 * Sum a rollup column over a day.
 */
function rolled(string $column, string $day = '2026-10-16'): int
{
    return (int) DailyRollup::query()->where('date', $day)->sum($column);
}

/**
 * A realistic mixed day: completed, warned, blocked, failed, streamed, failover, approximate, GBP and tagged requests.
 */
function seedDay(string $day = '2026-10-16'): void
{
    on($day)->count(5)->create();
    on($day)->count(2)->warned()->create();
    on($day)->blocked()->create();
    on($day)->failed()->create();
    on($day)->streamed()->create();
    on($day)->abandoned()->create();
    on($day)->failover('gpt-5')->create();
    on($day)->approximateCost()->create();
    on($day)->inCurrency('GBP')->count(2)->create();
    on($day)->countMethod(CountMethod::Native)->withTags(['tenant_id' => 42])->count(3)->create();
    on($day)->forAgent('App\\Ai\\Agents\\SupportAgent')->withTags(['tenant_id' => 7])->create(['agent' => null]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-17 12:00:00');
    config(['headroom-ledger.tags' => ['allowed' => ['tenant_id'], 'primary_tag' => 'tenant_id']]);
});

it('rolls a day up to the same totals as the raw entries', function (): void {
    seedDay();
    on('2026-10-15')->count(3)->create();

    (new DailyRollupBuilder)->build('2026-10-16');

    $day = Ledger::query()->between(CarbonImmutable::parse('2026-10-16'), CarbonImmutable::parse('2026-10-16'));
    $spend = $day->spend();
    $tokens = $day->tokens();
    $accuracy = $day->accuracy()->overall();

    expect(rolled('requests'))->toBe($spend->requests)
        ->and(rolled('requests_with_usage'))->toBe($spend->requestsWithUsage)
        ->and(rolled('estimated_only_requests'))->toBe($spend->estimateOnlyRequests)
        ->and(rolled('estimated_input_tokens'))->toBe($tokens->estimatedInput)
        ->and(rolled('actual_input_tokens'))->toBe($tokens->input)
        ->and(rolled('output_tokens'))->toBe($tokens->output)
        ->and(rolled('cache_read_tokens'))->toBe($tokens->cacheRead)
        ->and(rolled('cache_write_tokens'))->toBe($tokens->cacheWrite)
        ->and(rolled('accuracy_samples'))->toBe($accuracy->samples)
        ->and(rolled('accuracy_estimated_tokens'))->toBe($accuracy->estimatedTokens)
        ->and(rolled('accuracy_actual_tokens'))->toBe($accuracy->actualTokens)
        ->and(rolled('accuracy_absolute_error'))->toBe($accuracy->absoluteError);

    foreach (['USD', 'GBP'] as $currency) {
        $rows = DailyRollup::query()->where('currency', $currency);

        expect((int) (clone $rows)->sum('actual_cost'))->toBe($spend->actual->in($currency)?->microUnits ?? 0)
            ->and((int) (clone $rows)->sum('estimated_only_cost'))->toBe($spend->estimatedOnly->in($currency)?->microUnits ?? 0);
    }
});

it('counts outcomes, streams and approximate costs', function (): void {
    seedDay();

    (new DailyRollupBuilder)->build('2026-10-16');

    expect(rolled('requests'))->toBe(19)
        ->and(rolled('warned'))->toBe(2)
        ->and(rolled('blocked'))->toBe(1)
        ->and(rolled('failed'))->toBe(1)
        ->and(rolled('completed'))->toBe(14)
        ->and(rolled('streamed'))->toBe(2)
        ->and(rolled('approximate_requests'))->toBe(1)
        ->and(rolled('priced_requests'))->toBe(rolled('requests_with_usage'));
});

it('groups by agent, provider and model answered, count method, currency and primary tag', function (): void {
    on('2026-10-16')->forModel('claude-sonnet-5-5')->forAgent('App\\Ai\\Agents\\SupportAgent')->countMethod(CountMethod::Estimated)->count(2)->create();
    on('2026-10-16')->forModel('claude-sonnet-5-5')->failover('gpt-5')->forAgent('App\\Ai\\Agents\\SupportAgent')->countMethod(CountMethod::Estimated)->create();
    on('2026-10-16')->forModel('claude-sonnet-5-5')->forAgent('App\\Ai\\Agents\\SupportAgent')->countMethod(CountMethod::Estimated)->inCurrency('GBP')->create();
    on('2026-10-16')->forModel('claude-sonnet-5-5')->forAgent('App\\Ai\\Agents\\SupportAgent')->countMethod(CountMethod::Estimated)->withTags(['tenant_id' => 42])->create();

    expect((new DailyRollupBuilder)->build('2026-10-16'))->toBe(4);

    $rows = DailyRollup::query()->get()->map(fn (DailyRollup $row): string => implode('|', [
        $row->provider, $row->model, $row->currency, $row->primary_tag_key, $row->primary_tag_value ?? '-', $row->requests,
    ]))->sort()->values()->all();

    expect($rows)->toBe([
        'anthropic|claude-sonnet-5-5|GBP|tenant_id|-|1',
        'anthropic|claude-sonnet-5-5|USD|tenant_id|-|2',
        'anthropic|claude-sonnet-5-5|USD|tenant_id|42|1',
        'openai|gpt-5|USD|tenant_id|-|1',
    ]);
});

it('keeps average and maximum context usage', function (): void {
    sameGroup()->create(['estimated_input_tokens' => 20_000]);
    sameGroup()->create(['estimated_input_tokens' => 60_000]);

    (new DailyRollupBuilder)->build('2026-10-16');

    $row = DailyRollup::query()->sole();

    expect($row)
        ->context_samples->toBe(2)
        ->percent_used_sum->toBe(40.0)
        ->percent_used_max->toBe(30.0);
});

it('rolls up rows that have only a date', function (): void {
    event(headroomLow(['at' => '2026-10-16 10:00:00']));

    (new DailyRollupBuilder)->build('2026-10-16');

    expect(DailyRollup::query()->sole())
        ->requests->toBe(1)
        ->warned->toBe(1)
        ->model->toBeNull()
        ->currency->toBeNull()
        ->context_samples->toBe(0);
});

it('gives the same rows when run again', function (): void {
    seedDay();
    $builder = new DailyRollupBuilder;

    $builder->build('2026-10-16');
    $first = DailyRollup::query()->orderBy('id')->get()->map->only(['agent', 'model', 'currency', 'primary_tag_value', 'requests', 'actual_cost'])->sortBy(fn ($r) => serialize($r))->values()->all();

    $builder->build(CarbonImmutable::parse('2026-10-16 23:00'));
    $second = DailyRollup::query()->orderBy('id')->get()->map->only(['agent', 'model', 'currency', 'primary_tag_value', 'requests', 'actual_cost'])->sortBy(fn ($r) => serialize($r))->values()->all();

    expect($second)->toBe($first)
        ->and(rolled('requests'))->toBe(19);
});

it('picks up entries that arrived after the last run', function (): void {
    on('2026-10-16')->create();
    (new DailyRollupBuilder)->build('2026-10-16');

    on('2026-10-16')->count(2)->create();
    (new DailyRollupBuilder)->build('2026-10-16');

    expect(rolled('requests'))->toBe(3);
});

it('leaves other days alone', function (): void {
    on('2026-10-15')->count(2)->create();
    on('2026-10-16')->create();
    (new DailyRollupBuilder)->build('2026-10-15');

    (new DailyRollupBuilder)->build('2026-10-16');

    expect(rolled('requests', '2026-10-15'))->toBe(2)
        ->and(rolled('requests', '2026-10-16'))->toBe(1);
});

it('clears a day whose entries are gone', function (): void {
    on('2026-10-16')->create();
    (new DailyRollupBuilder)->build('2026-10-16');
    Entry::query()->delete();

    expect((new DailyRollupBuilder)->build('2026-10-16'))->toBe(0)
        ->and(DailyRollup::query()->count())->toBe(0);
});

it('rolls up without a primary tag', function (?string $primary, array $allowed): void {
    config(['headroom-ledger.tags' => ['allowed' => $allowed, 'primary_tag' => $primary]]);
    sameGroup()->withTags(['tenant_id' => 42])->count(2)->create();

    (new DailyRollupBuilder)->build('2026-10-16');

    expect(DailyRollup::query()->sole())
        ->requests->toBe(2)
        ->primary_tag_key->toBeNull()
        ->primary_tag_value->toBeNull();
})->with([
    'none configured' => [null, ['tenant_id']],
    'not allowlisted' => ['tenant_id', []],
]);

it('accounts for every request across many groups', function (): void {
    LedgerEntryFactory::new()->count(60)->create(['counted_at' => '2026-10-16 10:00:00', 'used_at' => '2026-10-16 10:00:05']);

    (new DailyRollupBuilder)->build('2026-10-16');

    expect(rolled('requests'))->toBe(60);
});
