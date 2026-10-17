<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroomLedger\Database\Factories\LedgerEntryFactory;
use Phoenix1331\AiHeadroomLedger\Enums\Outcome;
use Phoenix1331\AiHeadroomLedger\Models\Entry;

/**
 * The invariants every factory entry must hold, whatever states were combined.
 */
function expectConsistent(Entry $entry): void
{
    $spec = LedgerEntryFactory::spec($entry->model_counted);

    expect($entry->context_window)->toBe($spec['window'])
        ->and(array_sum($entry->breakdown ?? []))->toBe($entry->estimated_input_tokens)
        ->and($entry->percent_used)->toBe(round(($entry->estimated_input_tokens ?? 0) / $spec['window'] * 100, 2))
        ->and($entry->estimated_cost)->toBe((int) round(($entry->estimated_input_tokens ?? 0) * $spec['input'] + LedgerEntryFactory::ESTIMATED_OUTPUT_TOKENS * $spec['output']))
        ->and($entry->occurred_on->toDateString())->toBe($entry->counted_at?->toDateString());

    if ($entry->used_at === null) {
        expect($entry)
            ->actual_input_tokens->toBeNull()
            ->output_tokens->toBeNull()
            ->actual_cost->toBeNull()
            ->model_used->toBeNull();

        return;
    }

    $used = LedgerEntryFactory::spec($entry->model_used);

    expect($entry->used_at->greaterThan($entry->counted_at))->toBeTrue()
        ->and($entry->actual_input_tokens)->toBeGreaterThan(0)
        ->and($entry->cache_read_tokens)->toBeLessThanOrEqual($entry->actual_input_tokens)
        ->and($entry->actual_cost)->toBe((int) round($entry->actual_input_tokens * $used['input'] + ($entry->output_tokens ?? 0) * $used['output']));
}

it('creates a consistent completed entry by default', function (): void {
    $entry = Entry::factory()->create()->fresh();

    expect($entry)->outcome->toBe(Outcome::Completed)
        ->and($entry?->model_used)->toBe($entry?->model_counted)
        ->and($entry?->currency)->toBe('USD')
        ->and($entry?->streamed)->toBeFalse()
        ->and($entry?->cost_approximate)->toBeFalse();
    expectConsistent($entry);
});

it('makes entries without touching the database', function (): void {
    $entry = LedgerEntryFactory::new()->make();

    expect(Entry::query()->count())->toBe(0);
    expectConsistent($entry);
});

it('creates many distinct entries', function (): void {
    LedgerEntryFactory::new()->count(25)->create();

    expect(Entry::query()->distinct()->count('request_id'))->toBe(25);
    Entry::query()->get()->each(fn (Entry $entry) => expectConsistent($entry));
});

it('creates counted-only entries with no usage', function (): void {
    $entry = LedgerEntryFactory::new()->counted()->create()->fresh();

    expect($entry?->outcome)->toBe(Outcome::Counted)
        ->and($entry?->used_at)->toBeNull();
    expectConsistent($entry);
});

it('turns a counted entry back into a completed one', function (): void {
    $entry = LedgerEntryFactory::new()->counted()->completed()->create();

    expect($entry->outcome)->toBe(Outcome::Completed)
        ->and($entry->used_at)->not->toBeNull();
    expectConsistent($entry);
});

it('creates warned entries between 80 and 95 percent of context that still completed', function (): void {
    $entry = LedgerEntryFactory::new()->warned()->create();

    expect($entry)
        ->outcome->toBe(Outcome::Warned)
        ->outcome_reason->toBe('max_percent_of_context')
        ->percent_used->toBeGreaterThanOrEqual(79.99)->toBeLessThanOrEqual(95.01)
        ->used_at->not->toBeNull();
    expectConsistent($entry);
});

it('creates blocked entries over the context window that were never sent', function (): void {
    $entry = LedgerEntryFactory::new()->blocked()->create();

    expect($entry)
        ->outcome->toBe(Outcome::Blocked)
        ->outcome_reason->toBe('context_window')
        ->percent_used->toBeGreaterThan(100.0)
        ->used_at->toBeNull();
    expectConsistent($entry);
});

it('creates failed entries with the exception class and status only', function (): void {
    $default = LedgerEntryFactory::new()->failed()->create();
    $custom = LedgerEntryFactory::new()->failed('RuntimeException', null)->create();

    expect($default->outcome)->toBe(Outcome::Failed)
        ->and($default->outcome_reason)->toBe('Illuminate\\Http\\Client\\RequestException:529')
        ->and($custom->outcome_reason)->toBe('RuntimeException');
    expectConsistent($default);
});

it('creates streamed and abandoned entries', function (): void {
    $streamed = LedgerEntryFactory::new()->streamed()->create();
    $abandoned = LedgerEntryFactory::new()->abandoned()->create();

    expect($streamed->streamed)->toBeTrue()
        ->and($streamed->used_at)->not->toBeNull()
        ->and($abandoned->streamed)->toBeTrue()
        ->and($abandoned->outcome)->toBe(Outcome::Counted)
        ->and($abandoned->used_at)->toBeNull();
    expectConsistent($abandoned);
});

it('creates approximate-cost entries with cache reads', function (): void {
    $entry = LedgerEntryFactory::new()->approximateCost()->create();

    expect($entry->cost_approximate)->toBeTrue()
        ->and($entry->cache_read_tokens)->toBe((int) round(($entry->actual_input_tokens ?? 0) * 0.6));
    expectConsistent($entry);
});

it('records failover to another model, priced at the model that answered', function (): void {
    $entry = LedgerEntryFactory::new()->forModel('claude-sonnet-5-5')->failover('gpt-5')->create();

    expect($entry)
        ->provider_counted->toBe('anthropic')
        ->model_counted->toBe('claude-sonnet-5-5')
        ->provider_used->toBe('openai')
        ->model_used->toBe('gpt-5');
    expectConsistent($entry);
});

it('groups steps of one run under a shared invocation and agent, in order', function (): void {
    $steps = LedgerEntryFactory::new()->count(3)->inRun('run-1', 'App\\Ai\\Agents\\SupportAgent')->create();

    expect($steps->pluck('invocation_id')->unique()->all())->toBe(['run-1'])
        ->and($steps->pluck('agent')->unique()->all())->toBe(['App\\Ai\\Agents\\SupportAgent'])
        ->and($steps[1]->counted_at?->diffInMinutes($steps[0]->counted_at, true))->toEqual(1)
        ->and($steps[2]->counted_at?->diffInMinutes($steps[0]->counted_at, true))->toEqual(2);
    $steps->each(fn (Entry $entry) => expectConsistent($entry));
});

it('generates a run ID when none is given', function (): void {
    $steps = LedgerEntryFactory::new()->count(2)->inRun()->create();

    expect($steps->pluck('invocation_id')->unique())->toHaveCount(1);
});

it('keeps a blocked step in a run unused', function (): void {
    $entry = LedgerEntryFactory::new()->blocked()->inRun()->create();

    expect($entry->used_at)->toBeNull();
    expectConsistent($entry);
});

it('counts against a known or unknown model', function (): void {
    $known = LedgerEntryFactory::new()->forModel('gemini-2.5-flash')->create();
    $unknown = LedgerEntryFactory::new()->forModel('local-llama')->create();
    $named = LedgerEntryFactory::new()->forModel('mistral-large', 'mistral')->create();

    expect($known->provider_counted)->toBe('gemini')
        ->and($known->context_window)->toBe(1_000_000)
        ->and($unknown->provider_counted)->toBe('custom')
        ->and($unknown->context_window)->toBe(128_000)
        ->and($named->provider_counted)->toBe('mistral');
    expectConsistent($unknown);
});

it('sets the agent, count method and currency', function (): void {
    $entry = LedgerEntryFactory::new()
        ->forAgent('App\\Ai\\Agents\\ResearchAgent')
        ->countMethod(CountMethod::Native)
        ->inCurrency('gbp')
        ->create();

    expect($entry)
        ->agent->toBe('App\\Ai\\Agents\\ResearchAgent')
        ->count_method->toBe(CountMethod::Native)
        ->currency->toBe('GBP');
});

it('places an entry at a given time, with usage after it on the same day', function (): void {
    $entry = LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-09-01 10:00:00'))->create()->fresh();

    expect($entry?->counted_at?->toDateTimeString())->toBe('2026-09-01 10:00:00')
        ->and($entry?->occurred_on->toDateString())->toBe('2026-09-01')
        ->and($entry?->used_at?->toDateString())->toBe('2026-09-01');
});

it('places new entries within the last 30 days', function (): void {
    Carbon::setTestNow('2026-10-17 12:00:00');

    LedgerEntryFactory::new()->count(20)->create();

    expect(Entry::query()->min('counted_at'))->toBeGreaterThanOrEqual('2026-09-17 12:00:00')
        ->and(Entry::query()->max('counted_at'))->toBeLessThanOrEqual('2026-10-17 12:00:00');
});

it('stores tags as strings', function (): void {
    $entry = LedgerEntryFactory::new()->withTags(['tenant_id' => 42, 'beta' => true, 'trial' => false, 'plan' => 'pro'])->create();

    expect($entry->tags()->pluck('value', 'key')->all())
        ->toEqual(['tenant_id' => '42', 'beta' => '1', 'trial' => '0', 'plan' => 'pro']);
});

it('keeps an explicitly given figure instead of deriving it', function (): void {
    $entry = LedgerEntryFactory::new()->create(['actual_cost' => 1, 'estimated_input_tokens' => 1_000]);

    expect($entry->actual_cost)->toBe(1)
        ->and($entry->estimated_input_tokens)->toBe(1_000);
});
