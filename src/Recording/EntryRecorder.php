<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Recording;

use Phoenix1331\AiHeadroom\Events\TokensCounted;
use Phoenix1331\AiHeadroom\Events\TokensUsed;
use Phoenix1331\AiHeadroomLedger\Enums\Outcome;
use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Support\MicroUnits;

/**
 * Writes Headroom events to the ledger. Every write is a single upsert keyed
 * by request ID that touches only the columns the event owns, so the final
 * row is the same whatever order queued listeners run in. A null from an
 * event means "unknown" and never overwrites a value already recorded.
 *
 * @internal
 */
final class EntryRecorder
{
    /** The largest value percent_used (decimal 7,2) can hold. */
    private const float MAX_PERCENT = 99_999.99;

    /**
     * Pre-flight figures. Never changes the outcome, so a count that arrives
     * after usage, a warning or a block cannot downgrade the row.
     */
    public function counted(TokensCounted $event): void
    {
        $this->upsert([
            'request_id' => $event->requestId,
            'invocation_id' => $event->invocationId,
            'agent' => $event->agent,
            'provider_counted' => $event->provider,
            'model_counted' => $event->model,
            'count_method' => $event->countMethod,
            'estimated_input_tokens' => $event->estimatedInputTokens,
            'breakdown' => $event->breakdown,
            'context_window' => $event->contextWindow,
            'percent_used' => min($event->percentUsed, self::MAX_PERCENT),
            'currency' => $event->estimatedCost->currency->value,
            'estimated_cost' => MicroUnits::fromAmount($event->estimatedCost->total()),
            'occurred_on' => $event->occurredAt->toDateString(),
            'counted_at' => $event->occurredAt,
        ], insertOnly: ['outcome' => Outcome::Counted]);
    }

    /**
     * What the provider actually used and what it cost. Marks the request
     * completed unless it was warned, which already implies it was answered.
     * A blocked or failed row becomes completed: usage proves the call went
     * through, for example after failover.
     */
    public function used(TokensUsed $event): void
    {
        $this->upsert([
            'request_id' => $event->requestId,
            'invocation_id' => $event->invocationId,
            'agent' => $event->agent,
            'provider_used' => $event->provider,
            'model_used' => $event->model,
            'actual_input_tokens' => $event->inputTokens,
            'output_tokens' => $event->outputTokens,
            'cache_read_tokens' => $event->cacheReadTokens,
            'cache_write_tokens' => $event->cacheWriteTokens,
            'actual_cost' => MicroUnits::fromAmount($event->cost->total()),
            'cost_approximate' => $event->cost->approximate,
            'streamed' => $event->streamed,
            'used_at' => $event->occurredAt,
        ], insertOnly: [
            'model_counted' => $event->countedModel,
            'count_method' => $event->countMethod,
            'estimated_input_tokens' => $event->estimatedInputTokens,
            'currency' => $event->cost->currency->value,
            'occurred_on' => $event->occurredAt->toDateString(),
            'outcome' => Outcome::Completed,
        ]);

        // A separate conditional update rather than an expression in the upsert, so no raw SQL
        // is needed: warned stays warned, any other outcome becomes completed.
        Entry::query()
            ->whereKey($event->requestId)
            ->whereNot('outcome', Outcome::Warned)
            ->whereNot('outcome', Outcome::Completed)
            ->update(['outcome' => Outcome::Completed]);
    }

    /**
     * Insert the row, or update only the given columns if it exists. Values go
     * through the model's casts first, so the privacy guards still apply.
     *
     * @param  array<string, mixed>  $attributes  Columns this event owns; written on insert, and on update when not null.
     * @param  array<string, mixed>  $insertOnly  Columns set only when the row is new.
     */
    private function upsert(array $attributes, array $insertOnly = []): void
    {
        $values = (new Entry)->fill($attributes + $insertOnly)->getAttributes();

        $owned = array_keys(array_filter(
            array_diff_key($attributes, ['request_id' => true]),
            fn (mixed $value): bool => $value !== null,
        ));

        Entry::query()->upsert([$values], ['request_id'], $owned);
    }
}
