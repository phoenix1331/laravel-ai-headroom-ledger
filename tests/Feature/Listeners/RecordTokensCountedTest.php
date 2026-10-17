<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Phoenix1331\AiHeadroom\Data\Cost;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Enums\Currency;
use Phoenix1331\AiHeadroom\Events\TokensCounted;
use Phoenix1331\AiHeadroomLedger\AiHeadroomLedgerServiceProvider;
use Phoenix1331\AiHeadroomLedger\Database\Factories\LedgerEntryFactory;
use Phoenix1331\AiHeadroomLedger\Enums\Outcome;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordTokensCounted;
use Phoenix1331\AiHeadroomLedger\Models\Entry;

it('listens for TokensCounted', function (): void {
    Event::fake();

    Event::assertListening(TokensCounted::class, RecordTokensCounted::class);
});

it('records a counted request', function (): void {
    event(tokensCounted(['at' => '2026-10-17 09:30:00']));

    $entry = Entry::query()->findOrFail('01JREQUEST000000000000000A');

    expect($entry)
        ->invocation_id->toBe('0199f3a2-0000-7000-8000-000000000001')
        ->agent->toBe('App\\Ai\\Agents\\SupportAgent')
        ->provider_counted->toBe('anthropic')
        ->model_counted->toBe('claude-sonnet-5-5')
        ->count_method->toBe(CountMethod::Estimated)
        ->estimated_input_tokens->toBe(1_200)
        ->breakdown->toBe(['instructions' => 300, 'history' => 600, 'tools' => 100, 'attachments' => 0, 'prompt' => 200])
        ->context_window->toBe(200_000)
        ->percent_used->toBe(0.6)
        ->currency->toBe('USD')
        ->estimated_cost->toBe(18_960)
        ->outcome->toBe(Outcome::Counted)
        ->outcome_reason->toBeNull()
        ->occurred_on->toDateString()->toBe('2026-10-17')
        ->counted_at->toEqual(CarbonImmutable::parse('2026-10-17 09:30:00'))
        ->used_at->toBeNull()
        ->actual_input_tokens->toBeNull()
        ->actual_cost->toBeNull()
        ->streamed->toBeFalse();
});

it('keeps sub-cent estimates exact', function (): void {
    event(tokensCounted(['cost' => new Cost(0.000003, 0.0000005, Currency::Gbp)]));

    expect(Entry::query()->firstOrFail())
        ->estimated_cost->toBe(4)
        ->currency->toBe('GBP');
});

it('records counts without an agent, provider or invocation', function (): void {
    event(tokensCounted(['agent' => null, 'provider' => null, 'invocationId' => null]));

    expect(Entry::query()->firstOrFail())
        ->agent->toBeNull()
        ->provider_counted->toBeNull()
        ->invocation_id->toBeNull();
});

it('caps percent used at what the column can hold', function (): void {
    event(tokensCounted(['total' => 50_000_000, 'contextWindow' => 8_000, 'breakdown' => ['history' => 50_000_000]]));

    expect(Entry::query()->firstOrFail()->percent_used)->toBe(99_999.99);
});

it('records each request once, however often the count arrives', function (): void {
    event(tokensCounted(['total' => 1_000]));
    event(tokensCounted(['total' => 1_500]));

    expect(Entry::query()->count())->toBe(1)
        ->and(Entry::query()->firstOrFail()->estimated_input_tokens)->toBe(1_500);
});

it('keeps separate requests separate', function (): void {
    event(tokensCounted(['requestId' => 'a']));
    event(tokensCounted(['requestId' => 'b']));

    expect(Entry::query()->pluck('request_id')->sort()->values()->all())->toBe(['a', 'b']);
});

it('fills in the count without touching usage that arrived first', function (): void {
    LedgerEntryFactory::new()->create([
        'request_id' => '01JREQUEST000000000000000A',
        'model_counted' => null,
        'provider_counted' => null,
        'estimated_input_tokens' => 1_100,
        'breakdown' => null,
        'context_window' => null,
        'estimated_cost' => null,
        'actual_input_tokens' => 1_250,
        'output_tokens' => 300,
        'actual_cost' => 8_250,
        'counted_at' => null,
        'used_at' => '2026-10-17 09:30:05',
    ]);

    event(tokensCounted(['at' => '2026-10-17 09:30:00']));

    expect(Entry::query()->findOrFail('01JREQUEST000000000000000A'))
        ->outcome->toBe(Outcome::Completed)
        ->model_counted->toBe('claude-sonnet-5-5')
        ->estimated_input_tokens->toBe(1_200)
        ->breakdown->not->toBeNull()
        ->estimated_cost->toBe(18_960)
        ->counted_at->toEqual(CarbonImmutable::parse('2026-10-17 09:30:00'))
        ->actual_input_tokens->toBe(1_250)
        ->output_tokens->toBe(300)
        ->actual_cost->toBe(8_250)
        ->used_at->toEqual(CarbonImmutable::parse('2026-10-17 09:30:05'));
});

it('never downgrades a later outcome', function (Outcome $outcome): void {
    LedgerEntryFactory::new()->create(['request_id' => '01JREQUEST000000000000000A', 'outcome' => $outcome]);

    event(tokensCounted());

    expect(Entry::query()->findOrFail('01JREQUEST000000000000000A')->outcome)->toBe($outcome);
})->with([Outcome::Completed, Outcome::Warned, Outcome::Blocked, Outcome::Failed]);

it('registers no listeners when the ledger is disabled', function (): void {
    config(['headroom-ledger.enabled' => false]);
    $events = new Dispatcher($this->app);
    $this->app->instance(DispatcherContract::class, $events);

    (new AiHeadroomLedgerServiceProvider($this->app))->boot();

    expect($events->hasListeners(TokensCounted::class))->toBeFalse();
});
