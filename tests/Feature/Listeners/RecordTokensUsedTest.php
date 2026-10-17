<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Phoenix1331\AiHeadroom\Data\Cost;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Enums\Currency;
use Phoenix1331\AiHeadroom\Events\TokensUsed;
use Phoenix1331\AiHeadroomLedger\Database\Factories\LedgerEntryFactory;
use Phoenix1331\AiHeadroomLedger\Enums\Outcome;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordTokensUsed;
use Phoenix1331\AiHeadroomLedger\Models\Entry;

function recordedEntry(): Entry
{
    return Entry::query()->findOrFail('01JREQUEST000000000000000A');
}

it('listens for TokensUsed', function (): void {
    Event::fake();

    Event::assertListening(TokensUsed::class, RecordTokensUsed::class);
});

it('completes a counted request with its usage', function (): void {
    event(tokensCounted(['at' => '2026-10-17 09:30:00']));
    event(tokensUsed());

    expect(recordedEntry())
        ->outcome->toBe(Outcome::Completed)
        ->provider_used->toBe('anthropic')
        ->model_used->toBe('claude-sonnet-5-5')
        ->actual_input_tokens->toBe(1_250)
        ->output_tokens->toBe(300)
        ->cache_read_tokens->toBeNull()
        ->cache_write_tokens->toBeNull()
        ->actual_cost->toBe(8_250)
        ->cost_approximate->toBeFalse()
        ->streamed->toBeFalse()
        ->used_at->toEqual(CarbonImmutable::parse('2026-10-17 09:30:05'))
        ->estimated_input_tokens->toBe(1_200)
        ->estimated_cost->toBe(18_960)
        ->counted_at->toEqual(CarbonImmutable::parse('2026-10-17 09:30:00'));
});

it('records usage that arrives before the count, then fills in the count', function (): void {
    event(tokensUsed());

    expect(recordedEntry())
        ->outcome->toBe(Outcome::Completed)
        ->model_counted->toBe('claude-sonnet-5-5')
        ->count_method->toBe(CountMethod::Estimated)
        ->estimated_input_tokens->toBe(1_200)
        ->currency->toBe('USD')
        ->occurred_on->toDateString()->toBe('2026-10-17')
        ->counted_at->toBeNull()
        ->breakdown->toBeNull();

    event(tokensCounted(['at' => '2026-10-17 09:30:00']));

    expect(recordedEntry())
        ->outcome->toBe(Outcome::Completed)
        ->counted_at->toEqual(CarbonImmutable::parse('2026-10-17 09:30:00'))
        ->breakdown->not->toBeNull()
        ->context_window->toBe(200_000)
        ->actual_input_tokens->toBe(1_250)
        ->actual_cost->toBe(8_250);
});

it('ends with the same row whichever event arrives first', function (): void {
    event(tokensCounted(['requestId' => 'count-first', 'at' => '2026-10-17 09:30:00']));
    event(tokensUsed(['requestId' => 'count-first']));
    event(tokensUsed(['requestId' => 'usage-first']));
    event(tokensCounted(['requestId' => 'usage-first', 'at' => '2026-10-17 09:30:00']));

    $rows = Entry::query()->orderBy('request_id')->get()
        ->map(fn (Entry $entry): array => collect($entry->getAttributes())->except('request_id')->all());

    expect($rows[0])->toEqual($rows[1]);
});

it('keeps the counted model separate from the model that answered after failover', function (): void {
    event(tokensCounted());
    event(tokensUsed(['provider' => 'openai', 'model' => 'gpt-5']));

    expect(recordedEntry())
        ->provider_counted->toBe('anthropic')
        ->model_counted->toBe('claude-sonnet-5-5')
        ->provider_used->toBe('openai')
        ->model_used->toBe('gpt-5');
});

it('records cache tokens and approximate costs', function (): void {
    event(tokensUsed([
        'cacheReadTokens' => 800,
        'cacheWriteTokens' => 200,
        'cost' => new Cost(0.0012, 0.0045, Currency::Usd, approximate: true),
    ]));

    expect(recordedEntry())
        ->cache_read_tokens->toBe(800)
        ->cache_write_tokens->toBe(200)
        ->actual_cost->toBe(5_700)
        ->cost_approximate->toBeTrue();
});

it('records streamed requests', function (): void {
    event(tokensUsed(['streamed' => true]));

    expect(recordedEntry()->streamed)->toBeTrue();
});

it('keeps an abandoned stream as counted when usage never arrives', function (): void {
    event(tokensCounted());

    expect(recordedEntry())
        ->outcome->toBe(Outcome::Counted)
        ->used_at->toBeNull()
        ->actual_cost->toBeNull();
});

it('keeps a warned request warned once it completes', function (): void {
    LedgerEntryFactory::new()->warned()->counted()->create(['request_id' => '01JREQUEST000000000000000A', 'outcome' => Outcome::Warned]);

    event(tokensUsed());

    expect(recordedEntry())
        ->outcome->toBe(Outcome::Warned)
        ->outcome_reason->toBe('max_percent_of_context')
        ->actual_input_tokens->toBe(1_250);
});

it('completes a request that was marked failed or blocked once usage proves it was answered', function (Outcome $outcome): void {
    LedgerEntryFactory::new()->counted()->create(['request_id' => '01JREQUEST000000000000000A', 'outcome' => $outcome]);

    event(tokensUsed());

    expect(recordedEntry()->outcome)->toBe(Outcome::Completed);
})->with([Outcome::Failed, Outcome::Blocked]);

it('never overwrites a known invocation or agent with an unknown one', function (): void {
    event(tokensUsed());
    event(tokensCounted(['invocationId' => null, 'agent' => null]));

    expect(recordedEntry())
        ->invocation_id->toBe('0199f3a2-0000-7000-8000-000000000001')
        ->agent->toBe('App\\Ai\\Agents\\SupportAgent');
});

it('fills in an invocation the count did not know', function (): void {
    event(tokensCounted(['invocationId' => null]));
    event(tokensUsed(['invocationId' => 'run-9']));

    expect(recordedEntry()->invocation_id)->toBe('run-9');
});

it('records each step of an agent run under one invocation', function (): void {
    foreach (['step-1', 'step-2', 'step-3'] as $step) {
        event(tokensCounted(['requestId' => $step, 'invocationId' => 'run-1']));
        event(tokensUsed(['requestId' => $step, 'invocationId' => 'run-1']));
    }

    expect(Entry::query()->where('invocation_id', 'run-1')->count())->toBe(3)
        ->and(Entry::query()->where('outcome', Outcome::Completed)->count())->toBe(3);
});

it('records the same usage once, however often it arrives', function (): void {
    event(tokensUsed());
    event(tokensUsed());

    expect(Entry::query()->count())->toBe(1)
        ->and(recordedEntry()->actual_cost)->toBe(8_250);
});
