<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroomLedger\Data\AccuracyStat;
use Phoenix1331\AiHeadroomLedger\Data\AccuracyStats;
use Phoenix1331\AiHeadroomLedger\Data\Breakdown;
use Phoenix1331\AiHeadroomLedger\Database\Factories\LedgerEntryFactory;
use Phoenix1331\AiHeadroomLedger\Facades\Ledger;

/**
 * A completed request counted and answered by the same model, with exact token figures.
 */
function sample(int $estimated, int $actual, string $model = 'claude-sonnet-5-5', CountMethod $method = CountMethod::Estimated, array $attributes = []): void
{
    LedgerEntryFactory::new()->forModel($model)->countMethod($method)->create($attributes + [
        'estimated_input_tokens' => $estimated,
        'actual_input_tokens' => $actual,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-17 12:00:00');
});

it('measures estimate error per model and count method', function (): void {
    sample(900, 1_000);
    sample(1_100, 1_000);
    sample(1_000, 1_000, method: CountMethod::Native);
    sample(500, 400, model: 'gpt-5');

    $accuracy = Ledger::query()->accuracy();

    expect($accuracy)->toBeInstanceOf(AccuracyStats::class)->toHaveCount(3)
        ->and(array_map(fn (AccuracyStat $s): string => $s->model.'/'.$s->countMethod?->value, $accuracy->all()))
        ->toBe(['claude-sonnet-5-5/estimated', 'claude-sonnet-5-5/native', 'gpt-5/estimated']);

    expect($accuracy->for('claude-sonnet-5-5', CountMethod::Estimated))
        ->samples->toBe(2)
        ->estimatedTokens->toBe(2_000)
        ->actualTokens->toBe(2_000)
        ->absoluteError->toBe(200)
        ->and($accuracy->for('claude-sonnet-5-5', CountMethod::Estimated)?->meanAbsoluteError())->toBe(100.0)
        ->and($accuracy->for('claude-sonnet-5-5', CountMethod::Estimated)?->errorPercent())->toBe(10.0)
        ->and($accuracy->for('claude-sonnet-5-5', CountMethod::Estimated)?->biasPercent())->toBe(0.0)
        ->and($accuracy->for('claude-sonnet-5-5', CountMethod::Native)?->errorPercent())->toBe(0.0);
});

it('signs the bias: positive when estimates run low', function (): void {
    sample(800, 1_000);
    sample(500, 400, model: 'gpt-5');

    $accuracy = Ledger::query()->accuracy();

    expect($accuracy->for('claude-sonnet-5-5')?->biasPercent())->toBe(20.0)
        ->and($accuracy->for('gpt-5')?->biasPercent())->toBe(-25.0)
        ->and($accuracy->for('gpt-5')?->errorPercent())->toBe(25.0);
});

it('combines methods for a model, and everything overall', function (): void {
    sample(900, 1_000);
    sample(1_000, 1_000, method: CountMethod::Native);
    sample(500, 400, model: 'gpt-5');

    $accuracy = Ledger::query()->accuracy();

    expect($accuracy->for('claude-sonnet-5-5'))
        ->samples->toBe(2)
        ->model->toBe('claude-sonnet-5-5')
        ->countMethod->toBeNull()
        ->and($accuracy->overall())
        ->samples->toBe(3)
        ->model->toBeNull()
        ->absoluteError->toBe(200)
        ->and($accuracy->for('unknown-model'))->toBeNull();
});

it('leaves out requests without reported usage', function (): void {
    sample(900, 1_000);
    LedgerEntryFactory::new()->forModel('claude-sonnet-5-5')->countMethod(CountMethod::Estimated)->blocked()->create();
    LedgerEntryFactory::new()->forModel('claude-sonnet-5-5')->countMethod(CountMethod::Estimated)->counted()->create();

    expect(Ledger::query()->accuracy()->overall()->samples)->toBe(1);
});

it('leaves out failover answers, which another tokeniser produced', function (): void {
    sample(900, 1_000);
    LedgerEntryFactory::new()->forModel('claude-sonnet-5-5')->failover('gpt-5')->create();

    expect(Ledger::query()->accuracy()->overall()->samples)->toBe(1);
});

it('handles estimates far above the actual count without going negative', function (): void {
    sample(50_000, 10);

    expect(Ledger::query()->accuracy()->overall())
        ->absoluteError->toBe(49_990)
        ->and(Ledger::query()->accuracy()->overall()->biasPercent())->toBe(-499_900.0);
});

it('respects filters', function (): void {
    sample(900, 1_000, attributes: ['agent' => 'App\\Ai\\Agents\\SupportAgent']);
    sample(500, 1_000, attributes: ['agent' => 'App\\Ai\\Agents\\ResearchAgent']);

    expect(Ledger::query()->forAgent('App\\Ai\\Agents\\SupportAgent')->accuracy()->overall()->errorPercent())->toBe(10.0);
});

it('is empty for an empty range, with no error to report', function (): void {
    sample(900, 1_000, attributes: ['counted_at' => '2026-01-01 09:00:00', 'used_at' => '2026-01-01 09:00:05']);

    $accuracy = Ledger::query()->lastDays(30)->accuracy();

    expect($accuracy->isEmpty())->toBeTrue()
        ->and($accuracy->overall())
        ->samples->toBe(0)
        ->and($accuracy->overall()->meanAbsoluteError())->toBeNull()
        ->and($accuracy->overall()->errorPercent())->toBeNull()
        ->and($accuracy->overall()->biasPercent())->toBeNull();
});

it('records accuracy from real events', function (): void {
    event(tokensCounted());
    event(tokensUsed());

    expect(Ledger::query()->accuracy()->for('claude-sonnet-5-5', CountMethod::Estimated))
        ->samples->toBe(1)
        ->estimatedTokens->toBe(1_200)
        ->actualTokens->toBe(1_250);
});

it('can be iterated and turned into an array', function (): void {
    sample(900, 1_000);

    $accuracy = Ledger::query()->accuracy();

    expect(iterator_to_array($accuracy))->toHaveCount(1)
        ->and($accuracy->toArray()[0])->toBe([
            'model' => 'claude-sonnet-5-5',
            'count_method' => 'estimated',
            'samples' => 1,
            'estimated_tokens' => 900,
            'actual_tokens' => 1_000,
            'absolute_error' => 100,
            'mean_absolute_error' => 100.0,
            'error_percent' => 10.0,
            'bias_percent' => 10.0,
        ]);
});

describe('grouped', function (): void {
    it('measures accuracy per day, matching a query for that day alone', function (): void {
        sample(900, 1_000, attributes: ['counted_at' => '2026-10-15 09:00:00', 'used_at' => '2026-10-15 09:00:05']);
        sample(1_000, 1_000, method: CountMethod::Native, attributes: ['counted_at' => '2026-10-15 10:00:00', 'used_at' => '2026-10-15 10:00:05']);
        sample(500, 400, model: 'gpt-5', attributes: ['counted_at' => '2026-10-17 09:00:00', 'used_at' => '2026-10-17 09:00:05']);

        $byDay = Ledger::query()->lastDays(3)->byDay()->accuracy();

        expect($byDay)->toBeInstanceOf(Breakdown::class)
            ->and($byDay->keys())->toBe(['2026-10-15', '2026-10-16', '2026-10-17']);

        foreach ($byDay->keys() as $day) {
            $alone = Ledger::query()->between(CarbonImmutable::parse((string) $day), CarbonImmutable::parse((string) $day))->accuracy();

            expect($byDay->get($day))->toBeInstanceOf(AccuracyStats::class)
                ->and($byDay->get($day)?->toArray())->toBe($alone->toArray());
        }

        expect($byDay->get('2026-10-15'))->toHaveCount(2)
            ->and($byDay->get('2026-10-16')?->isEmpty())->toBeTrue()
            ->and($byDay->get('2026-10-17')?->for('gpt-5')?->biasPercent())->toBe(-25.0);
    });

    it('groups by model, agent, provider, tag and run', function (): void {
        sample(900, 1_000, attributes: ['agent' => 'App\\Ai\\Agents\\SupportAgent', 'invocation_id' => 'run-1']);
        sample(500, 400, model: 'gpt-5', attributes: ['agent' => 'App\\Ai\\Agents\\ResearchAgent', 'invocation_id' => 'run-2']);
        LedgerEntryFactory::new()->forModel('gpt-5')->countMethod(CountMethod::Estimated)->withTags(['tenant_id' => 42])
            ->create(['estimated_input_tokens' => 100, 'actual_input_tokens' => 110, 'invocation_id' => 'run-2', 'agent' => 'App\\Ai\\Agents\\ResearchAgent']);

        $query = Ledger::query();

        expect($query->byModel()->accuracy()->keys())->toBe(['claude-sonnet-5-5', 'gpt-5'])
            ->and($query->byModel()->accuracy()->get('gpt-5')?->overall()->samples)->toBe(2)
            ->and($query->byProvider()->accuracy()->get('anthropic')?->overall()->errorPercent())->toBe(10.0)
            ->and($query->byAgent()->accuracy()->get('App\\Ai\\Agents\\SupportAgent')?->overall()->samples)->toBe(1)
            ->and($query->byRun()->accuracy()->get('run-2')?->overall()->samples)->toBe(2)
            ->and($query->byTag('tenant_id')->accuracy()->keys())->toBe(['42', null])
            ->and($query->byTag('tenant_id')->accuracy()->get(null)?->overall()->samples)->toBe(2);
    });

    it('leaves out groups without samples unless they are days in the range', function (): void {
        sample(900, 1_000, attributes: ['agent' => 'App\\Ai\\Agents\\SupportAgent', 'counted_at' => '2026-10-17 09:00:00', 'used_at' => '2026-10-17 09:00:05']);
        LedgerEntryFactory::new()->forAgent('App\\Ai\\Agents\\ResearchAgent')->blocked()->at(CarbonImmutable::parse('2026-10-16 09:00'))->create();

        expect(Ledger::query()->byAgent()->accuracy()->keys())->toBe(['App\\Ai\\Agents\\SupportAgent'])
            ->and(Ledger::query()->byDay()->accuracy()->keys())->toBe(['2026-10-17']);
    });

    it('turns into an array of groups', function (): void {
        sample(900, 1_000);

        expect(Ledger::query()->byModel()->accuracy()->toArray())->toBe([[
            'key' => 'claude-sonnet-5-5',
            'value' => Ledger::query()->accuracy()->toArray(),
        ]]);
    });
});
