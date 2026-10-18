<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroomLedger\Database\Factories\LedgerEntryFactory;
use Phoenix1331\AiHeadroomLedger\Enums\Outcome;
use Phoenix1331\AiHeadroomLedger\Facades\Ledger;
use Phoenix1331\AiHeadroomLedger\Models\DailyRollup;
use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Models\RollupDay;
use Phoenix1331\AiHeadroomLedger\Query\Aggregator;
use Phoenix1331\AiHeadroomLedger\Query\Grouping;
use Phoenix1331\AiHeadroomLedger\Query\LedgerQuery;
use Phoenix1331\AiHeadroomLedger\Rollup\DailyRollupBuilder;

/**
 * Evaluate a query with rollups switched off, reading raw entries only.
 */
function rawOnly(Closure $query): mixed
{
    config(['headroom-ledger.rollup.enabled' => false]);

    try {
        return $query();
    } finally {
        config(['headroom-ledger.rollup.enabled' => true]);
    }
}

function rollUpDays(string ...$days): void
{
    foreach ($days as $day) {
        (new DailyRollupBuilder)->build($day);
    }
}

function forgetEntriesOn(string ...$days): void
{
    Entry::query()->whereIn('occurred_on', $days)->delete();
}

function spendOf(LedgerQuery $query): int
{
    return $query->spend()->total->in('USD')?->microUnits ?? 0;
}

/**
 * Ten days of mixed traffic, including today.
 */
function seedTenDays(): void
{
    foreach (range(9, 0) as $daysAgo) {
        $day = CarbonImmutable::today()->subDays($daysAgo)->setTime(10, 0);
        $factory = LedgerEntryFactory::new()->at($day);

        $factory->count(3)->create();
        $factory->warned()->create();
        $factory->blocked()->withTags(['tenant_id' => 42])->create();
        $factory->failover('gpt-5')->withTags(['tenant_id' => 7, 'plan' => 'pro'])->create();
        $factory->approximateCost()->countMethod(CountMethod::Native)->create();
        $factory->inCurrency('GBP')->withTags(['tenant_id' => 42])->create();
        $factory->forAgent('App\\Ai\\Agents\\SupportAgent')->withTags(['tenant_id' => 42, 'plan' => 'free'])->count(2)->create();
        $factory->abandoned()->create(['agent' => null]);
    }
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-17 12:00:00');
    config(['headroom-ledger.tags' => ['allowed' => ['tenant_id', 'plan'], 'primary_tag' => 'tenant_id']]);
});

/**
 * @return array<string, Closure(): mixed>
 */
function aggregates(): array
{
    return [
        'spend' => fn () => Ledger::query()->spend()->toArray(),
        'tokens' => fn () => Ledger::query()->tokens()->toArray(),
        'accuracy' => fn () => Ledger::query()->accuracy()->toArray(),
        'context' => fn () => Ledger::query()->context()->toArray(),
        'by day context' => fn () => Ledger::query()->lastDays(10)->byDay()->context()->toArray(),
        'by primary tag context' => fn () => Ledger::query()->byTag('tenant_id')->context()->toArray(),
        'last 3 days spend' => fn () => Ledger::query()->lastDays(3)->spend()->toArray(),
        'range spanning rolled and raw days' => fn () => Ledger::query()->between(CarbonImmutable::parse('2026-10-11'), CarbonImmutable::parse('2026-10-17'))->tokens()->toArray(),
        'by model spend' => fn () => Ledger::query()->byModel()->spend()->toArray(),
        'by model tokens' => fn () => Ledger::query()->byModel()->tokens()->toArray(),
        'by provider' => fn () => Ledger::query()->byProvider()->spend()->toArray(),
        'by agent' => fn () => Ledger::query()->byAgent()->spend()->toArray(),
        'by day' => fn () => Ledger::query()->lastDays(10)->byDay()->spend()->toArray(),
        'by primary tag' => fn () => Ledger::query()->byTag('tenant_id')->spend()->toArray(),
        'primary tag filter' => fn () => Ledger::query()->forTag('tenant_id', 42)->byModel()->tokens()->toArray(),
        'agent filter' => fn () => Ledger::query()->forAgent('App\\Ai\\Agents\\SupportAgent')->spend()->toArray(),
        'model filter' => fn () => Ledger::query()->forModel('gpt-5')->accuracy()->toArray(),
        'count method entries' => fn () => Ledger::query()->forCountMethod(CountMethod::Native)->count(),
        'count method spend' => fn () => Ledger::query()->forCountMethod(CountMethod::Native)->spend()->toArray(),
        'count methods tokens' => fn () => Ledger::query()->forCountMethod(CountMethod::Estimated, CountMethod::Calibrated)->tokens()->toArray(),
        'count method accuracy' => fn () => Ledger::query()->forCountMethod(CountMethod::Native)->accuracy()->toArray(),
        'count method by day' => fn () => Ledger::query()->lastDays(10)->forCountMethod(CountMethod::Native)->byDay()->spend()->toArray(),
        'provider filter' => fn () => Ledger::query()->forProvider('openai')->byDay()->tokens()->toArray(),
        'near limit (raw only)' => fn () => Ledger::query()->nearLimit(50)->spend()->toArray(),
        'blocked (raw only)' => fn () => Ledger::query()->blocked()->byModel()->spend()->toArray(),
        'outcome (raw only)' => fn () => Ledger::query()->withOutcome(Outcome::Completed)->tokens()->toArray(),
        'by run (raw only)' => fn () => Ledger::query()->byRun()->tokens()->count(),
        'other tag (raw only)' => fn () => Ledger::query()->forTag('plan', 'pro')->spend()->toArray(),
        'by other tag (raw only)' => fn () => Ledger::query()->byTag('plan')->spend()->toArray(),
        'two tags (raw only)' => fn () => Ledger::query()->forTag('tenant_id', 42)->forTag('plan', 'free')->spend()->toArray(),
    ];
}

it('gives exactly the raw answer for every aggregate when closed days come from rollups', function (): void {
    // Seeded once and checked together: re-seeding per case is slow on MySQL and MariaDB.
    seedTenDays();
    rollUpDays(...array_map(fn (int $daysAgo): string => CarbonImmutable::today()->subDays($daysAgo)->toDateString(), range(9, 3)));

    $mismatches = [];

    foreach (aggregates() as $name => $aggregate) {
        if ($aggregate() != rawOnly($aggregate)) {
            $mismatches[] = $name;
        }
    }

    expect($mismatches)->toBe([]);
});

it('reads rolled-up days from rollups, so pruned entries still count', function (): void {
    seedTenDays();
    $before = spendOf(Ledger::query());
    rollUpDays('2026-10-10', '2026-10-11');

    forgetEntriesOn('2026-10-10', '2026-10-11');

    expect(spendOf(Ledger::query()))->toBe($before)
        ->and(rawOnly(fn () => spendOf(Ledger::query())))->toBeLessThan($before);
});

it('answers a count method filter from rollups', function (): void {
    LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-10-15 10:00'))->countMethod(CountMethod::Native)->create(['actual_cost' => 100]);
    LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-10-15 10:00'))->countMethod(CountMethod::Estimated)->create(['actual_cost' => 200]);
    rollUpDays('2026-10-15');

    forgetEntriesOn('2026-10-15');

    expect(spendOf(Ledger::query()->forCountMethod(CountMethod::Native)))->toBe(100)
        ->and(spendOf(Ledger::query()->forCountMethod(CountMethod::Native, CountMethod::Estimated)))->toBe(300);
});

it('reads days that were not rolled up from raw entries', function (): void {
    LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-10-15 10:00'))->create(['actual_cost' => 100]);
    LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-10-16 10:00'))->create(['actual_cost' => 200]);
    rollUpDays('2026-10-15');

    expect(spendOf(Ledger::query()->lastDays(3)))->toBe(300);
});

it('never reads today from rollups, even if it was rolled up by hand', function (): void {
    LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-10-17 09:00'))->create(['actual_cost' => 100]);
    rollUpDays('2026-10-17');
    LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-10-17 11:00'))->create(['actual_cost' => 200]);

    expect(spendOf(Ledger::query()))->toBe(300);
});

it('treats a rolled-up day with no entries as covered', function (): void {
    rollUpDays('2026-10-15');
    LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-10-15 10:00'))->create(['actual_cost' => 100]);

    expect(spendOf(Ledger::query()))->toBe(0);

    rollUpDays('2026-10-15');

    expect(spendOf(Ledger::query()))->toBe(100);
});

it('falls back to raw entries for filters and groupings rollups cannot answer', function (): void {
    seedTenDays();
    rollUpDays('2026-10-10');

    $queries = [
        'near limit' => fn () => Ledger::query()->nearLimit(1)->tokens()->toArray(),
        'blocked' => fn () => Ledger::query()->blocked()->tokens()->toArray(),
        'by run' => fn () => Ledger::query()->byRun()->tokens()->count(),
        'other tag' => fn () => Ledger::query()->forTag('plan', 'pro')->tokens()->toArray(),
        'by other tag' => fn () => Ledger::query()->byTag('plan')->tokens()->toArray(),
    ];
    $before = array_map(fn (Closure $query): mixed => $query(), $queries);

    forgetEntriesOn('2026-10-10');

    // Raw-only answers change when the day's entries go; a rollup answer would not.
    $unchanged = array_keys(array_filter($queries, fn (Closure $query, string $name): bool => $query() == $before[$name], ARRAY_FILTER_USE_BOTH));

    expect($unchanged)->toBe([]);
});

it('falls back to raw entries for tag queries on days rolled up with a different primary tag', function (): void {
    LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-10-15 10:00'))->withTags(['tenant_id' => 42, 'plan' => 'pro'])->create(['actual_cost' => 100]);
    rollUpDays('2026-10-15');

    config(['headroom-ledger.tags.primary_tag' => 'plan']);
    forgetEntriesOn('2026-10-15');

    expect(spendOf(Ledger::query()->forTag('plan', 'pro')))->toBe(0)
        ->and(spendOf(Ledger::query()))->toBe(100);
});

it('reads raw entries only when rollups are disabled', function (): void {
    LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-10-15 10:00'))->create(['actual_cost' => 100]);
    rollUpDays('2026-10-15');
    forgetEntriesOn('2026-10-15');
    config(['headroom-ledger.rollup.enabled' => false]);

    expect(spendOf(Ledger::query()))->toBe(0);
});

it('agrees with raw entries after forgetting a primary tag value', function (): void {
    seedTenDays();
    rollUpDays('2026-10-10', '2026-10-11');

    Ledger::forgetTag('tenant_id', 42);

    $query = fn () => Ledger::query()->byTag('tenant_id')->spend()->toArray();

    expect($query())->toEqual(rawOnly($query))
        ->and(Ledger::query()->forTag('tenant_id', 42)->spend()->requests)->toBe(0);
});

it('records each rolled-up day, its primary tag and row count', function (): void {
    LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-10-15 10:00'))->forModel('gpt-5')->forAgent('App\\Ai\\Agents\\SupportAgent')
        ->countMethod(CountMethod::Native)->create();

    rollUpDays('2026-10-15', '2026-10-14');

    expect(RollupDay::query()->findOrFail('2026-10-15'))
        ->primary_tag_key->toBe('tenant_id')
        ->rows->toBe(1)
        ->built_at->toEqual(CarbonImmutable::now())
        ->and(RollupDay::query()->findOrFail('2026-10-14')->rows)->toBe(0)
        ->and(RollupDay::query()->count())->toBe(2);
});

it('refuses to read runs from rollups', function (): void {
    Aggregator::totals(Entry::query(), Grouping::Run, null, DailyRollup::query()->toBase());
})->throws(LogicException::class);

it('makes a concurrent rebuild of the same day wait for the first', function (): void {
    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        $this->markTestSkipped('SQLite allows one writer at a time, so rebuilds cannot interleave.');
    }

    config(['database.connections.locker' => config('database.connections.'.DB::getDefaultConnection())]);
    RollupDay::query()->insert(['date' => '2026-10-15']);
    $locker = DB::connection('locker');
    $locker->beginTransaction();
    $locker->table('headroom_ledger_rollup_days')->where('date', '2026-10-15')->lockForUpdate()->first();

    $driver === 'pgsql'
        ? DB::statement("set lock_timeout = '1s'")
        : DB::statement('set session innodb_lock_wait_timeout = 1');

    try {
        expect(fn () => (new DailyRollupBuilder)->build('2026-10-15'))->toThrow(QueryException::class);
    } finally {
        $locker->rollBack();
        $locker->disconnect();
    }

    expect((new DailyRollupBuilder)->build('2026-10-15'))->toBe(0);
});
