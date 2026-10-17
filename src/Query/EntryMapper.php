<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Query;

use Phoenix1331\AiHeadroomLedger\Data\LedgerEntry;
use Phoenix1331\AiHeadroomLedger\Data\Money;
use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Models\Tag;

/**
 * Turns internal rows into public value objects, so no Eloquent model ever
 * leaves the package.
 *
 * @internal
 */
final class EntryMapper
{
    public static function toEntry(Entry $row): LedgerEntry
    {
        $currency = $row->currency;

        return new LedgerEntry(
            requestId: $row->request_id,
            invocationId: $row->invocation_id,
            agent: $row->agent,
            providerCounted: $row->provider_counted,
            modelCounted: $row->model_counted,
            providerUsed: $row->provider_used,
            modelUsed: $row->model_used,
            countMethod: $row->count_method,
            estimatedInputTokens: $row->estimated_input_tokens,
            breakdown: $row->breakdown,
            contextWindow: $row->context_window,
            percentUsed: $row->percent_used,
            actualInputTokens: $row->actual_input_tokens,
            outputTokens: $row->output_tokens,
            cacheReadTokens: $row->cache_read_tokens,
            cacheWriteTokens: $row->cache_write_tokens,
            estimatedCost: $currency === null || $row->estimated_cost === null
                ? null
                : new Money($row->estimated_cost, $currency, estimated: true),
            actualCost: $currency === null || $row->actual_cost === null
                ? null
                : new Money($row->actual_cost, $currency, approximate: $row->cost_approximate),
            streamed: $row->streamed,
            outcome: $row->outcome,
            outcomeReason: $row->outcome_reason,
            countedAt: $row->counted_at,
            usedAt: $row->used_at,
            tags: $row->relationLoaded('tags')
                ? $row->tags->mapWithKeys(fn (Tag $tag): array => [$tag->key => $tag->value])->sortKeys()->all()
                : [],
        );
    }
}
