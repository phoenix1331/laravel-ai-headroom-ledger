<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Phoenix1331\AiHeadroomLedger\Data\Breakdown;
use Phoenix1331\AiHeadroomLedger\Data\SpendSummary;
use Phoenix1331\AiHeadroomLedger\Data\TokenTotals;
use Phoenix1331\AiHeadroomLedger\Database\Factories\LedgerEntryFactory;
use Phoenix1331\AiHeadroomLedger\Exceptions\CurrencyMismatch;
use Phoenix1331\AiHeadroomLedger\Facades\Ledger;

/**
 * A completed request with exact figures.
 */
function completed(array $attributes = []): void
{
    LedgerEntryFactory::new()->create($attributes + [
        'estimated_input_tokens' => 1_000,
        'actual_input_tokens' => 1_100,
        'output_tokens' => 200,
        'cache_read_tokens' => 0,
        'cache_write_tokens' => 0,
        'estimated_cost' => 5_000,
        'actual_cost' => 4_000,
    ]);
}

/**
 * A request that never reported usage, with an exact estimate.
 */
function estimateOnly(array $attributes = []): void
{
    LedgerEntryFactory::new()->blocked()->create($attributes + [
        'estimated_input_tokens' => 2_000,
        'estimated_cost' => 9_000,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-17 12:00:00');
});

describe('spend', function (): void {
    it('uses actual cost where known and the estimate otherwise, keeping both parts', function (): void {
        completed();
        completed();
        estimateOnly();

        $spend = Ledger::query()->spend();

        expect($spend)->toBeInstanceOf(SpendSummary::class)
            ->requests->toBe(3)
            ->requestsWithUsage->toBe(2)
            ->estimateOnlyRequests->toBe(1)
            ->and($spend->isEstimated())->toBeTrue()
            ->and($spend->actual->sole())->microUnits->toBe(8_000)->estimated->toBeFalse()
            ->and($spend->estimatedOnly->sole())->microUnits->toBe(9_000)->estimated->toBeTrue()
            ->and($spend->total->sole())->microUnits->toBe(17_000)->estimated->toBeTrue()->currency->toBe('USD');
    });

    it('is not flagged as estimated when every request reported usage', function (): void {
        completed();

        $spend = Ledger::query()->spend();

        expect($spend->isEstimated())->toBeFalse()
            ->and($spend->total->sole()->estimated)->toBeFalse()
            ->and($spend->estimatedOnly->isEmpty())->toBeTrue();
    });

    it('keeps currencies apart and never sums them', function (): void {
        completed(['currency' => 'USD']);
        completed(['currency' => 'GBP']);
        estimateOnly(['currency' => 'GBP']);

        $total = Ledger::query()->spend()->total;

        expect($total->currencies())->toBe(['GBP', 'USD'])
            ->and($total->in('USD')?->microUnits)->toBe(4_000)
            ->and($total->in('GBP')?->microUnits)->toBe(13_000)
            ->and(fn () => $total->sole())->toThrow(CurrencyMismatch::class);
    });

    it('flags an approximate actual cost', function (): void {
        completed();
        LedgerEntryFactory::new()->approximateCost()->create();

        expect(Ledger::query()->spend()->actual->sole()->approximate)->toBeTrue();
    });

    it('counts requests that have neither usage nor an estimate without giving them an amount', function (): void {
        completed();
        event(headroomLow(['requestId' => 'warning-only']));

        $spend = Ledger::query()->spend();

        expect($spend->requests)->toBe(2)
            ->and($spend->total->sole()->microUnits)->toBe(4_000)
            ->and($spend->isEstimated())->toBeFalse();
    });

    it('is empty for an empty range', function (): void {
        completed();

        $spend = Ledger::query()->between(CarbonImmutable::parse('2020-01-01'), CarbonImmutable::parse('2020-01-31'))->spend();

        expect($spend->requests)->toBe(0)
            ->and($spend->total->isEmpty())->toBeTrue()
            ->and($spend->isEstimated())->toBeFalse();
    });

    it('respects filters', function (): void {
        completed(['agent' => 'App\\Ai\\Agents\\SupportAgent']);
        completed(['agent' => 'App\\Ai\\Agents\\ResearchAgent', 'actual_cost' => 1]);

        expect(Ledger::query()->forAgent('App\\Ai\\Agents\\SupportAgent')->spend()->total->sole()->microUnits)->toBe(4_000);
    });

    it('converts to an array', function (): void {
        completed();

        expect(Ledger::query()->spend()->toArray())
            ->requests->toBe(1)
            ->total->toHaveCount(1)
            ->estimated_only->toBe([]);
    });
});

describe('tokens', function (): void {
    it('sums estimates over every request and actuals over requests with usage', function (): void {
        completed(['cache_read_tokens' => 300, 'cache_write_tokens' => 50]);
        completed();
        estimateOnly();

        expect(Ledger::query()->tokens())->toBeInstanceOf(TokenTotals::class)
            ->estimatedInput->toBe(4_000)
            ->input->toBe(2_200)
            ->output->toBe(400)
            ->cacheRead->toBe(300)
            ->cacheWrite->toBe(50)
            ->requests->toBe(3)
            ->requestsWithUsage->toBe(2);
    });

    it('is zero for an empty range', function (): void {
        expect(Ledger::query()->lastDays(7)->tokens()->toArray())
            ->toBe(['estimated_input' => 0, 'input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'requests' => 0, 'requests_with_usage' => 0]);
    });
});

describe('groupings', function (): void {
    it('groups by the model that answered, else the one counted', function (): void {
        completed(['model_counted' => 'claude-sonnet-5-5', 'model_used' => 'claude-sonnet-5-5']);
        completed(['model_counted' => 'claude-sonnet-5-5', 'model_used' => 'gpt-5', 'provider_used' => 'openai']);
        estimateOnly(['model_counted' => 'claude-sonnet-5-5']);

        $tokens = Ledger::query()->byModel()->tokens();

        expect($tokens)->toBeInstanceOf(Breakdown::class)
            ->and($tokens->keys())->toBe(['claude-sonnet-5-5', 'gpt-5'])
            ->and($tokens->get('claude-sonnet-5-5'))->requests->toBe(2)->requestsWithUsage->toBe(1)->input->toBe(1_100)
            ->and($tokens->get('gpt-5'))->requests->toBe(1)->output->toBe(200);
    });

    it('groups by provider', function (): void {
        completed(['provider_counted' => 'anthropic', 'provider_used' => 'openai', 'model_used' => 'gpt-5']);
        estimateOnly(['provider_counted' => 'anthropic']);

        expect(Ledger::query()->byProvider()->spend()->keys())->toBe(['anthropic', 'openai']);
    });

    it('groups by agent, with requests without one last', function (): void {
        completed(['agent' => 'App\\Ai\\Agents\\SupportAgent']);
        completed(['agent' => 'App\\Ai\\Agents\\ResearchAgent']);
        completed(['agent' => null]);

        $spend = Ledger::query()->byAgent()->spend();

        expect($spend->keys())->toBe(['App\\Ai\\Agents\\ResearchAgent', 'App\\Ai\\Agents\\SupportAgent', null])
            ->and($spend->get(null)?->requests)->toBe(1);
    });

    it('groups by day oldest first, filling days without requests in the range', function (): void {
        completed(['counted_at' => '2026-10-15 09:00:00', 'used_at' => '2026-10-15 09:00:05']);
        completed(['counted_at' => '2026-10-17 09:00:00', 'used_at' => '2026-10-17 09:00:05']);
        estimateOnly(['counted_at' => '2026-10-17 10:00:00']);

        $spend = Ledger::query()->lastDays(4)->byDay()->spend();

        expect($spend->keys())->toBe(['2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17'])
            ->and($spend->get('2026-10-14')?->requests)->toBe(0)
            ->and($spend->get('2026-10-14')?->total->isEmpty())->toBeTrue()
            ->and($spend->get('2026-10-15')?->total->sole()->microUnits)->toBe(4_000)
            ->and($spend->get('2026-10-17')?->total->sole())->microUnits->toBe(13_000)->estimated->toBeTrue();
    });

    it('lists only days with requests when there is no range', function (): void {
        completed(['counted_at' => '2026-10-01 09:00:00', 'used_at' => '2026-10-01 09:00:05']);
        completed(['counted_at' => '2026-10-03 09:00:00', 'used_at' => '2026-10-03 09:00:05']);

        expect(Ledger::query()->byDay()->tokens()->keys())->toBe(['2026-10-01', '2026-10-03']);
    });

    it('groups by agent run rather than by provider call', function (): void {
        foreach (['run-a', 'run-a', 'run-b'] as $run) {
            completed(['invocation_id' => $run]);
        }

        $spend = Ledger::query()->byRun()->spend();

        expect($spend->keys())->toBe(['run-a', 'run-b'])
            ->and($spend->get('run-a'))->requests->toBe(2)
            ->and($spend->get('run-a')?->total->sole()->microUnits)->toBe(8_000);
    });

    it('groups by a tag, with untagged requests in the null group', function (): void {
        LedgerEntryFactory::new()->withTags(['tenant_id' => 42, 'plan' => 'pro'])->create(['actual_cost' => 100]);
        LedgerEntryFactory::new()->withTags(['tenant_id' => 42])->create(['actual_cost' => 200]);
        LedgerEntryFactory::new()->withTags(['tenant_id' => 7])->create(['actual_cost' => 400]);
        LedgerEntryFactory::new()->withTags(['plan' => 'pro'])->create(['actual_cost' => 800]);

        $spend = Ledger::query()->byTag('tenant_id')->spend();

        expect($spend->keys())->toBe(['7', '42', null])
            ->and($spend->get('42')?->total->sole()->microUnits)->toBe(300)
            ->and($spend->get('7')?->total->sole()->microUnits)->toBe(400)
            ->and($spend->get(null)?->total->sole()->microUnits)->toBe(800);
    });

    it('combines a tag grouping with a tag filter', function (): void {
        LedgerEntryFactory::new()->withTags(['tenant_id' => 42, 'plan' => 'pro'])->create(['actual_cost' => 100]);
        LedgerEntryFactory::new()->withTags(['tenant_id' => 42, 'plan' => 'free'])->create(['actual_cost' => 200]);
        LedgerEntryFactory::new()->withTags(['tenant_id' => 7, 'plan' => 'pro'])->create(['actual_cost' => 400]);

        $spend = Ledger::query()->forTag('tenant_id', 42)->byTag('plan')->spend();

        expect($spend->keys())->toBe(['free', 'pro'])
            ->and($spend->get('pro')?->total->sole()->microUnits)->toBe(100);
    });

    it('keeps currencies apart within a group', function (): void {
        completed(['model_counted' => 'gpt-5', 'model_used' => 'gpt-5', 'currency' => 'USD']);
        completed(['model_counted' => 'gpt-5', 'model_used' => 'gpt-5', 'currency' => 'GBP']);

        expect(Ledger::query()->byModel()->spend()->get('gpt-5')?->total->currencies())->toBe(['GBP', 'USD']);
    });

    it('is empty when nothing matches', function (): void {
        expect(Ledger::query()->byModel()->spend()->isEmpty())->toBeTrue()
            ->and(Ledger::query()->byModel()->tokens())->toHaveCount(0);
    });

    it('can be iterated, collected and turned into arrays', function (): void {
        completed(['model_counted' => 'gpt-5', 'model_used' => 'gpt-5']);

        $tokens = Ledger::query()->byModel()->tokens();

        expect(iterator_to_array($tokens)[0]->key)->toBe('gpt-5')
            ->and($tokens->all())->toHaveCount(1)
            ->and($tokens->collect()->first()?->value->input)->toBe(1_100)
            ->and($tokens->toArray()[0])->key->toBe('gpt-5')->value->toHaveKey('input', 1_100)
            ->and($tokens->get('missing'))->toBeNull();
    });
});
