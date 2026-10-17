<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroomLedger\Data\ContextUsage;
use Phoenix1331\AiHeadroomLedger\Database\Factories\LedgerEntryFactory;
use Phoenix1331\AiHeadroomLedger\Facades\Ledger;
use Phoenix1331\AiHeadroomLedger\Rollup\DailyRollupBuilder;

function seedReport(): void
{
    $today = LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-10-17 09:00'));
    $earlier = LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-10-12 09:00'));

    $today->forModel('claude-sonnet-5-5')->forAgent('App\\Ai\\Agents\\SupportAgent')->countMethod(CountMethod::Native)
        ->withTags(['tenant_id' => 42])->create(['estimated_input_tokens' => 1_000, 'actual_input_tokens' => 1_100, 'output_tokens' => 200, 'cache_read_tokens' => 300, 'actual_cost' => 312]);
    $today->forModel('gpt-5')->forAgent('App\\Ai\\Agents\\ResearchAgent')->countMethod(CountMethod::Estimated)
        ->create(['estimated_input_tokens' => 2_000, 'actual_input_tokens' => 1_800, 'output_tokens' => 100, 'actual_cost' => 1_250_000]);
    $earlier->forModel('claude-sonnet-5-5')->forAgent('App\\Ai\\Agents\\SupportAgent')->blocked()
        ->withTags(['tenant_id' => 7])->create(['estimated_cost' => 9_000]);
    $earlier->forModel('claude-sonnet-5-5')->countMethod(CountMethod::Native)->warned()->create(['agent' => null]);
    LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-09-01 09:00'))->create();
}

/**
 * @return array<string, mixed>
 */
function jsonReport(array $options = []): array
{
    Artisan::call('headroom-ledger:report', ['--json' => true, ...$options]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-17 12:00:00');
    config(['headroom-ledger.tags' => ['allowed' => ['tenant_id'], 'primary_tag' => 'tenant_id']]);
});

describe('context usage in the query API', function (): void {
    it('reports average and maximum context and limit outcomes', function (): void {
        LedgerEntryFactory::new()->create(['estimated_input_tokens' => 20_000, 'model_counted' => 'claude-sonnet-5-5']);
        LedgerEntryFactory::new()->warned()->create(['estimated_input_tokens' => 180_000, 'model_counted' => 'claude-sonnet-5-5']);
        LedgerEntryFactory::new()->blocked()->create(['estimated_input_tokens' => 220_000, 'model_counted' => 'claude-sonnet-5-5']);
        LedgerEntryFactory::new()->failed()->create(['estimated_input_tokens' => 40_000, 'model_counted' => 'claude-sonnet-5-5']);
        event(headroomLow(['requestId' => 'no-count']));

        expect(Ledger::query()->context())
            ->requests->toBe(5)
            ->warned->toBe(2)
            ->blocked->toBe(1)
            ->failed->toBe(1)
            ->samples->toBe(4)
            ->maxPercentUsed->toBe(110.0)
            ->and(Ledger::query()->context()->averagePercentUsed())->toBe(57.5);
    });

    it('has no averages without counted requests', function (): void {
        expect(Ledger::query()->context())->toBeInstanceOf(ContextUsage::class)
            ->and(Ledger::query()->context()->averagePercentUsed())->toBeNull()
            ->and(Ledger::query()->context()->toArray())->max_percent_used->toBeNull();
    });

    it('groups context usage, filling empty days', function (): void {
        seedReport();

        $context = Ledger::query()->lastDays(7)->byDay()->context();

        expect($context->keys())->toHaveCount(7)
            ->and($context->get('2026-10-12')?->blocked)->toBe(1)
            ->and($context->get('2026-10-13')?->requests)->toBe(0);
    });

    it('gives the same context usage from rollups as from raw entries', function (): void {
        seedReport();
        (new DailyRollupBuilder)->build('2026-10-12');
        $combined = Ledger::query()->byModel()->context()->toArray();

        config(['headroom-ledger.rollup.enabled' => false]);

        expect(Ledger::query()->byModel()->context()->toArray())->toEqual($combined);
    });
});

it('prints spend, context and accuracy tables for the last 7 days by model', function (): void {
    seedReport();

    $this->artisan('headroom-ledger:report')
        ->expectsOutputToContain('2026-10-11 to 2026-10-17')
        ->expectsOutputToContain('Spend and tokens')
        ->expectsOutputToContain('claude-sonnet-5-5')
        ->expectsOutputToContain('USD 1.25 ')
        ->expectsOutputToContain('Context and limits')
        ->expectsOutputToContain('Estimate accuracy')
        ->expectsOutputToContain('-11.1%')
        ->expectsOutputToContain('includes pre-flight estimates')
        ->assertSuccessful();
});

it('shows sub-cent costs and trims trailing zeros to two decimals', function (): void {
    LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-10-17 09:00'))->create(['actual_cost' => 312]);
    LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-10-16 09:00'))->inCurrency('GBP')->create(['actual_cost' => 2_500_000]);

    Artisan::call('headroom-ledger:report', ['--by' => 'day']);

    expect(Artisan::output())
        ->toContain('| USD 0.000312 ')
        ->toContain('| GBP 2.50 ')
        ->toContain('| GBP 2.50 + USD 0.000312 ');
});

it('names agents by class and groups without one as (none)', function (): void {
    seedReport();

    $this->artisan('headroom-ledger:report', ['--by' => 'agent'])
        ->expectsOutputToContain('SupportAgent')
        ->expectsOutputToContain('(none)')
        ->doesntExpectOutputToContain('App\\Ai\\Agents\\SupportAgent')
        ->assertSuccessful();
});

it('groups by provider, day, run or a tag', function (string $by, string $expected): void {
    seedReport();

    $this->artisan('headroom-ledger:report', ['--by' => $by])
        ->expectsOutputToContain($expected)
        ->assertSuccessful();
})->with([
    'provider' => ['provider', 'openai'],
    'day' => ['day', '2026-10-14'],
    'run' => ['run', 'Run'],
    'tag' => ['tag:tenant_id', 'Tenant_id'],
]);

it('reports from a given date through today', function (): void {
    seedReport();

    expect(jsonReport(['--since' => '2026-09-01']))
        ->from->toBe('2026-09-01')
        ->and(jsonReport(['--since' => '2026-09-01'])['totals']['spend']['requests'])->toBe(5)
        ->and(jsonReport(['--since' => '30d'])['totals']['spend']['requests'])->toBe(4);
});

it('says so when there were no requests', function (): void {
    $this->artisan('headroom-ledger:report')
        ->expectsOutputToContain('No requests in this period')
        ->assertSuccessful();
});

it('shows a placeholder when no request reported usage', function (): void {
    LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-10-17 09:00'))->blocked()->create();

    $this->artisan('headroom-ledger:report')
        ->expectsOutputToContain('No requests reported usage')
        ->assertSuccessful();
});

it('prints one JSON document with totals, groups and accuracy', function (): void {
    seedReport();

    $report = jsonReport(['--by' => 'model']);

    expect($report)
        ->from->toBe('2026-10-11')
        ->to->toBe('2026-10-17')
        ->by->toBe('model')
        ->and($report['totals']['spend']['requests'])->toBe(4)
        ->and($report['totals']['context'])->blocked->toBe(1)->warned->toBe(1)
        ->and(array_column($report['groups'], 'key'))->toBe(['claude-sonnet-5-5', 'gpt-5'])
        ->and($report['groups'][1]['tokens']['input'])->toBe(1_800)
        ->and($report['groups'][1]['spend']['total'][0]['micro_units'])->toBe(1_250_000)
        ->and($report['accuracy'])->toHaveCount(2)
        ->and($report['accuracy'][1])->model->toBe('gpt-5')->error_percent->toBe(11.11)->bias_percent->toBe(-11.11)
        ->and($report['accuracy_overall']['samples'])->toBe(3);
});

it('keeps JSON groups for days without requests', function (): void {
    seedReport();

    $report = jsonReport(['--by' => 'day']);

    expect($report['groups'])->toHaveCount(7)
        ->and($report['groups'][1])->key->toBe('2026-10-12')
        ->and($report['groups'][1]['context']['blocked'])->toBe(1);
});

it('rejects options it cannot use', function (array $options, string $message): void {
    $this->artisan('headroom-ledger:report', $options)
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'since in weeks' => [['--since' => '2w'], '--since must be'],
    'since zero days' => [['--since' => '0d'], '--since must be'],
    'since an impossible date' => [['--since' => '2026-02-30'], '--since must be'],
    'since the future' => [['--since' => '2026-12-01'], '--since must be'],
    'unknown grouping' => [['--by' => 'colour'], '--by must be'],
    'tag without a key' => [['--by' => 'tag:'], '--by must be'],
]);
