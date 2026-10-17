<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Recording;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Phoenix1331\AiHeadroom\Data\Cost;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Enums\LimitType;
use Phoenix1331\AiHeadroom\Events\HeadroomLow;
use Phoenix1331\AiHeadroom\Events\RequestBlocked;
use Phoenix1331\AiHeadroom\Events\RequestFailed;
use Phoenix1331\AiHeadroom\Events\TokensCounted;
use Phoenix1331\AiHeadroom\Events\TokensUsed;
use Phoenix1331\AiHeadroomLedger\Enums\Outcome;
use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Models\Tag;
use Phoenix1331\AiHeadroomLedger\Support\MicroUnits;
use Phoenix1331\AiHeadroomLedger\Support\TagValue;

/**
 * Writes Headroom events to the ledger, one row per request ID.
 *
 * Figures: each event upserts only the columns it owns, and a null from an
 * event means "unknown" and never overwrites a recorded value.
 *
 * Outcome: set on insert, then changed only by conditional updates, so the
 * row settles the same whatever order queued listeners run in:
 *
 *   usage reported         warned if a warning was seen, else completed
 *   blocked, no usage      blocked
 *   warned, no usage       warned (a warning outranks a failed attempt)
 *   failed, no usage       failed
 *   otherwise              counted
 *
 * Usage proves the call was answered, so it overrides blocked and failed
 * (for example after failover). With several warnings, the reason is the
 * highest-ranked limit in LimitType::cases() order.
 *
 * Tags: context tags are stored only for keys in headroom-ledger.tags.allowed,
 * which is empty by default, so nothing personal is recorded unless the app
 * opts in.
 *
 * @internal
 */
final class EntryRecorder
{
    /** The largest value percent_used (decimal 7,2) can hold. */
    private const float MAX_PERCENT = 99_999.99;

    /** Lengths of the tag key and value columns. */
    private const int MAX_TAG_KEY = 64;

    private const int MAX_TAG_VALUE = 191;

    public function __construct(private readonly ExceptionHandler $exceptions) {}

    public function counted(TokensCounted $event): void
    {
        $this->upsert(
            $this->countColumns(
                $event->requestId, $event->invocationId, $event->agent, $event->provider, $event->model,
                $event->countMethod, $event->estimatedInputTokens, $event->breakdown, $event->contextWindow,
                $event->percentUsed, $event->estimatedCost, $event->occurredAt,
            ),
            insertOnly: ['outcome' => Outcome::Counted],
        );

        $this->tags($event->requestId, $event->context);
    }

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

        $this->entry($event->requestId)
            ->whereIn('outcome', [Outcome::Counted, Outcome::Blocked, Outcome::Failed])
            ->update(['outcome' => Outcome::Completed, 'outcome_reason' => null]);

        $this->tags($event->requestId, $event->context);
    }

    /**
     * Blocked before sending. Carries the full count, used only if no
     * TokensCounted has been recorded yet.
     */
    public function blocked(RequestBlocked $event): void
    {
        $count = $event->count;
        $invocationId = $event->invocationId ?? $count->invocationId();

        // TokensCounted owns the count columns; the block's copy only fills a row it creates.
        $this->upsert(
            ['request_id' => $count->requestId(), 'invocation_id' => $invocationId],
            insertOnly: [
                ...$this->countColumns(
                    $count->requestId(), $invocationId, $count->agent(), $count->provider(),
                    $count->model(), $count->method(), $count->total(), $count->breakdown(), $count->contextWindow(),
                    $count->percentUsed(), $count->estimatedCost(), $event->occurredAt,
                ),
                'outcome' => Outcome::Blocked,
                'outcome_reason' => $event->limit,
            ],
        );

        $this->entry($count->requestId())
            ->whereNull('used_at')
            ->update(['outcome' => Outcome::Blocked, 'outcome_reason' => $event->limit->value]);

        $this->tags($count->requestId(), $count->context());
    }

    /**
     * Allowed through in report mode despite a breached limit.
     */
    public function warned(HeadroomLow $event): void
    {
        $this->upsert(
            ['request_id' => $event->requestId, 'invocation_id' => $event->invocationId],
            insertOnly: [
                'occurred_on' => $event->occurredAt->toDateString(),
                'outcome' => Outcome::Warned,
                'outcome_reason' => $event->limit,
            ],
        );

        $outranked = array_map(
            fn (LimitType $limit): string => $limit->value,
            array_slice(LimitType::cases(), array_search($event->limit, LimitType::cases(), true) + 1),
        );

        $this->entry($event->requestId)
            ->where(fn (Builder $query) => $query
                ->whereIn('outcome', [Outcome::Counted, Outcome::Completed, Outcome::Failed])
                ->orWhere(fn (Builder $query) => $query
                    ->where('outcome', Outcome::Warned)
                    ->whereIn('outcome_reason', $outranked)))
            ->update(['outcome' => Outcome::Warned, 'outcome_reason' => $event->limit->value]);
    }

    /**
     * The provider call threw. Recorded as the exception class and HTTP status only.
     */
    public function failed(RequestFailed $event): void
    {
        $reason = $event->httpStatus === null ? $event->exception : $event->exception.':'.$event->httpStatus;

        $this->upsert(
            ['request_id' => $event->requestId, 'invocation_id' => $event->invocationId],
            insertOnly: [
                'provider_counted' => $event->provider,
                'model_counted' => $event->model,
                'occurred_on' => $event->occurredAt->toDateString(),
                'outcome' => Outcome::Failed,
                'outcome_reason' => $reason,
            ],
        );

        $this->entry($event->requestId)
            ->where('outcome', Outcome::Counted)
            ->whereNull('used_at')
            ->update(['outcome' => Outcome::Failed, 'outcome_reason' => $reason]);
    }

    /**
     * Store the allowlisted context tags. Values are stored as strings; one too
     * long for the column is skipped and reported rather than truncated.
     *
     * @param  array<string, scalar>  $context
     */
    private function tags(string $requestId, array $context): void
    {
        $rows = [];

        foreach (array_intersect_key($context, array_flip($this->allowedTagKeys())) as $key => $value) {
            $value = TagValue::from($value);

            if (mb_strlen($value) > self::MAX_TAG_VALUE) {
                $this->exceptions->report(new InvalidArgumentException(
                    "Headroom Ledger skipped the \"{$key}\" tag: its value is longer than ".self::MAX_TAG_VALUE.' characters.'
                ));

                continue;
            }

            $rows[] = ['request_id' => $requestId, 'key' => $key, 'value' => $value];
        }

        if ($rows !== []) {
            Tag::query()->upsert($rows, ['request_id', 'key'], ['value']);
        }
    }

    /**
     * @return list<string>
     */
    private function allowedTagKeys(): array
    {
        $allowed = config('headroom-ledger.tags.allowed', []);

        if (! is_array($allowed)) {
            return [];
        }

        return array_values(array_filter(
            $allowed,
            fn (mixed $key): bool => is_string($key) && $key !== '' && mb_strlen($key) <= self::MAX_TAG_KEY,
        ));
    }

    /**
     * Pre-flight figures, shared by TokensCounted and RequestBlocked.
     *
     * @param  array<string, int>  $breakdown
     * @return array<string, mixed>
     */
    private function countColumns(
        string $requestId,
        ?string $invocationId,
        ?string $agent,
        ?string $provider,
        string $model,
        CountMethod $method,
        int $estimatedInputTokens,
        array $breakdown,
        int $contextWindow,
        float $percentUsed,
        Cost $estimatedCost,
        CarbonImmutable $occurredAt,
    ): array {
        return [
            'request_id' => $requestId,
            'invocation_id' => $invocationId,
            'agent' => $agent,
            'provider_counted' => $provider,
            'model_counted' => $model,
            'count_method' => $method,
            'estimated_input_tokens' => $estimatedInputTokens,
            'breakdown' => $breakdown,
            'context_window' => $contextWindow,
            'percent_used' => min($percentUsed, self::MAX_PERCENT),
            'currency' => $estimatedCost->currency->value,
            'estimated_cost' => MicroUnits::fromAmount($estimatedCost->total()),
            'occurred_on' => $occurredAt->toDateString(),
            'counted_at' => $occurredAt,
        ];
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

        // Laravel turns an upsert with nothing to update into a plain insert, which fails on an existing row.
        if ($owned === []) {
            Entry::query()->insertOrIgnore([$values]);

            return;
        }

        Entry::query()->upsert([$values], ['request_id'], $owned);
    }

    /**
     * @return Builder<Entry>
     */
    private function entry(string $requestId): Builder
    {
        return Entry::query()->whereKey($requestId);
    }
}
