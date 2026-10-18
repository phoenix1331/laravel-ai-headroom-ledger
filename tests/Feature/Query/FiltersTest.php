<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroomLedger\Data\LedgerEntries;
use Phoenix1331\AiHeadroomLedger\Data\LedgerEntry;
use Phoenix1331\AiHeadroomLedger\Database\Factories\LedgerEntryFactory;
use Phoenix1331\AiHeadroomLedger\Enums\Outcome;
use Phoenix1331\AiHeadroomLedger\Facades\Ledger;
use Phoenix1331\AiHeadroomLedger\Query\LedgerQuery;

/**
 * @return list<string>
 */
function ids(LedgerEntries $entries): array
{
    return array_map(fn (LedgerEntry $entry): string => $entry->requestId, $entries->all());
}

function at(string $time): LedgerEntryFactory
{
    return LedgerEntryFactory::new()->at(CarbonImmutable::parse($time));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-17 12:00:00');
});

it('returns everything, newest first, without filters', function (): void {
    at('2026-10-15 09:00')->create(['request_id' => 'old']);
    at('2026-10-17 09:00')->create(['request_id' => 'new']);
    at('2026-10-16 09:00')->create(['request_id' => 'mid']);

    expect(Ledger::query())->toBeInstanceOf(LedgerQuery::class)
        ->and(ids(Ledger::query()->entries()))->toBe(['new', 'mid', 'old'])
        ->and(Ledger::query()->count())->toBe(3);
});

it('limits the entries returned', function (): void {
    LedgerEntryFactory::new()->count(5)->create();

    expect(Ledger::query()->entries(2))->toHaveCount(2);
});

it('includes the tags on returned entries', function (): void {
    LedgerEntryFactory::new()->withTags(['tenant_id' => 42])->create();

    expect(Ledger::query()->entries()->first()?->tags)->toBe(['tenant_id' => '42']);
});

describe('dates', function (): void {
    beforeEach(function (): void {
        at('2026-10-09 23:59')->create(['request_id' => 'before']);
        at('2026-10-10 00:00')->create(['request_id' => 'first-day']);
        at('2026-10-17 11:00')->create(['request_id' => 'today']);
    });

    it('filters whole days, inclusive at both ends', function (): void {
        expect(ids(Ledger::query()->between(CarbonImmutable::parse('2026-10-10 18:00'), CarbonImmutable::parse('2026-10-17 01:00'))->entries()))
            ->toBe(['today', 'first-day']);
    });

    it('covers today and the days before it', function (): void {
        expect(ids(Ledger::query()->lastDays(8)->entries()))->toBe(['today', 'first-day'])
            ->and(ids(Ledger::query()->lastDays(1)->entries()))->toBe(['today']);
    });

    it('rejects a range that ends before it starts', function (): void {
        Ledger::query()->between(CarbonImmutable::parse('2026-10-17'), CarbonImmutable::parse('2026-10-10'));
    })->throws(InvalidArgumentException::class, 'starts (2026-10-17) after it ends (2026-10-10)');

    it('rejects fewer than one day', function (): void {
        Ledger::query()->lastDays(0);
    })->throws(InvalidArgumentException::class);

    it('reads dates in the app timezone', function (): void {
        config(['app.timezone' => 'UTC']);

        // 23:30 in New York on the 9th is already the 10th in UTC.
        expect(ids(Ledger::query()->between(
            CarbonImmutable::parse('2026-10-09 23:30', 'America/New_York'),
            CarbonImmutable::parse('2026-10-10 12:00', 'UTC'),
        )->entries()))->toBe(['first-day']);
    });

    it('includes rows that only have a date', function (): void {
        event(headroomLow(['requestId' => 'warning-only', 'at' => '2026-10-17 08:00:00']));

        expect(ids(Ledger::query()->lastDays(1)->entries()))->toContain('warning-only');
    });
});

it('filters by agent', function (): void {
    LedgerEntryFactory::new()->forAgent('App\\Ai\\Agents\\SupportAgent')->create(['request_id' => 'support']);
    LedgerEntryFactory::new()->forAgent('App\\Ai\\Agents\\ResearchAgent')->create();

    expect(ids(Ledger::query()->forAgent('App\\Ai\\Agents\\SupportAgent')->entries()))->toBe(['support']);
});

it('filters by the model that answered, else the one counted', function (): void {
    LedgerEntryFactory::new()->forModel('claude-sonnet-5-5')->create(['request_id' => 'sonnet']);
    LedgerEntryFactory::new()->forModel('claude-sonnet-5-5')->failover('gpt-5')->create(['request_id' => 'failed-over']);
    LedgerEntryFactory::new()->forModel('claude-sonnet-5-5')->blocked()->create(['request_id' => 'blocked']);

    expect(ids(Ledger::query()->forModel('claude-sonnet-5-5')->entries()))->toEqualCanonicalizing(['sonnet', 'blocked'])
        ->and(ids(Ledger::query()->forModel('gpt-5')->entries()))->toBe(['failed-over']);
});

it('filters by provider the same way', function (): void {
    LedgerEntryFactory::new()->forModel('claude-sonnet-5-5')->failover('gpt-5')->create(['request_id' => 'failed-over']);
    LedgerEntryFactory::new()->forModel('gemini-2.5-flash')->counted()->create(['request_id' => 'gemini']);

    expect(ids(Ledger::query()->forProvider('openai')->entries()))->toBe(['failed-over'])
        ->and(ids(Ledger::query()->forProvider('gemini')->entries()))->toBe(['gemini'])
        ->and(Ledger::query()->forProvider('anthropic')->count())->toBe(0);
});

it('filters by one or several tags', function (): void {
    LedgerEntryFactory::new()->withTags(['tenant_id' => 42, 'plan' => 'pro'])->create(['request_id' => 'pro-42']);
    LedgerEntryFactory::new()->withTags(['tenant_id' => 42, 'plan' => 'free'])->create(['request_id' => 'free-42']);
    LedgerEntryFactory::new()->withTags(['tenant_id' => 7])->create(['request_id' => 'seven']);
    LedgerEntryFactory::new()->create(['request_id' => 'untagged']);

    expect(ids(Ledger::query()->forTag('tenant_id', 42)->entries()))->toEqualCanonicalizing(['pro-42', 'free-42'])
        ->and(ids(Ledger::query()->forTag('tenant_id', '42')->forTag('plan', 'pro')->entries()))->toBe(['pro-42'])
        ->and(Ledger::query()->forTag('tenant_id', 99)->count())->toBe(0);
});

it('filters by one or several count methods', function (): void {
    LedgerEntryFactory::new()->countMethod(CountMethod::Native)->create(['request_id' => 'native']);
    LedgerEntryFactory::new()->countMethod(CountMethod::Calibrated)->create(['request_id' => 'calibrated']);
    LedgerEntryFactory::new()->countMethod(CountMethod::Estimated)->create(['request_id' => 'estimated']);
    event(headroomLow(['requestId' => 'warning-only']));

    expect(ids(Ledger::query()->forCountMethod(CountMethod::Native)->entries()))->toBe(['native'])
        ->and(ids(Ledger::query()->forCountMethod(CountMethod::Native, CountMethod::Calibrated, CountMethod::Native)->entries()))->toEqualCanonicalizing(['native', 'calibrated'])
        ->and(Ledger::query()->forCountMethod(CountMethod::Estimated)->count())->toBe(1)
        ->and(Ledger::query()->forCountMethod()->count())->toBe(4);
});

it('finds requests near their context limit', function (): void {
    LedgerEntryFactory::new()->create(['request_id' => 'at-80', 'estimated_input_tokens' => 160_000, 'model_counted' => 'claude-sonnet-5-5']);
    LedgerEntryFactory::new()->create(['request_id' => 'below', 'estimated_input_tokens' => 159_000, 'model_counted' => 'claude-sonnet-5-5']);
    LedgerEntryFactory::new()->blocked()->create(['request_id' => 'over']);

    expect(ids(Ledger::query()->nearLimit(80)->entries()))->toEqualCanonicalizing(['at-80', 'over'])
        ->and(ids(Ledger::query()->nearLimit()->entries()))->toEqualCanonicalizing(['at-80', 'over'])
        ->and(ids(Ledger::query()->nearLimit(100)->entries()))->toBe(['over']);
});

it('finds blocked and warned requests', function (): void {
    LedgerEntryFactory::new()->blocked()->create(['request_id' => 'blocked']);
    LedgerEntryFactory::new()->warned()->create(['request_id' => 'warned']);
    LedgerEntryFactory::new()->create();
    LedgerEntryFactory::new()->failed()->create(['request_id' => 'failed']);

    expect(ids(Ledger::query()->blocked()->entries()))->toEqualCanonicalizing(['blocked', 'warned'])
        ->and(ids(Ledger::query()->blocked(includeWarned: false)->entries()))->toBe(['blocked'])
        ->and(ids(Ledger::query()->withOutcome(Outcome::Failed, Outcome::Failed)->entries()))->toBe(['failed']);
});

it('combines filters', function (): void {
    at('2026-10-17 09:00')->forAgent('App\\Ai\\Agents\\SupportAgent')->warned()->withTags(['tenant_id' => 42])->create(['request_id' => 'match']);
    at('2026-10-17 09:00')->forAgent('App\\Ai\\Agents\\SupportAgent')->warned()->withTags(['tenant_id' => 7])->create();
    at('2026-10-01 09:00')->forAgent('App\\Ai\\Agents\\SupportAgent')->warned()->withTags(['tenant_id' => 42])->create();
    at('2026-10-17 09:00')->forAgent('App\\Ai\\Agents\\SupportAgent')->withTags(['tenant_id' => 42])->create();

    expect(ids(Ledger::query()->lastDays(7)->forAgent('App\\Ai\\Agents\\SupportAgent')->forTag('tenant_id', 42)->blocked()->entries()))
        ->toBe(['match']);
});

it('never changes a query that was filtered further', function (): void {
    LedgerEntryFactory::new()->forAgent('App\\Ai\\Agents\\SupportAgent')->create();
    LedgerEntryFactory::new()->forAgent('App\\Ai\\Agents\\ResearchAgent')->create();

    $base = Ledger::query();
    $support = $base->forAgent('App\\Ai\\Agents\\SupportAgent');

    expect($base->count())->toBe(2)
        ->and($support->count())->toBe(1)
        ->and($support)->not->toBe($base);
});

it('returns an empty list when nothing matches', function (): void {
    expect(Ledger::query()->lastDays(7)->entries()->isEmpty())->toBeTrue()
        ->and(Ledger::query()->count())->toBe(0);
});

it('reads a prefixed table on a separate connection', function (): void {
    config([
        'database.connections.ledger' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        'headroom-ledger.connection' => 'ledger',
        'headroom-ledger.table_prefix' => 'ai_',
    ]);
    $files = glob(__DIR__.'/../../../database/migrations/*.php') ?: [];
    $migration = require $files[0];
    DB::usingConnection('ledger', fn () => $migration->up());

    LedgerEntryFactory::new()->withTags(['tenant_id' => 42])->create(['request_id' => 'tagged']);

    expect(ids(Ledger::query()->forTag('tenant_id', 42)->entries()))->toBe(['tagged']);
});
