<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroomLedger\Data\AccuracyStat;
use Phoenix1331\AiHeadroomLedger\Data\AccuracyStats;
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
