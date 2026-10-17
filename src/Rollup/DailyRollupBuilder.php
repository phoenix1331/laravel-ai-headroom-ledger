<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Rollup;

use DateTimeInterface;
use Illuminate\Database\Query\JoinClause;
use Phoenix1331\AiHeadroomLedger\Models\DailyRollup;
use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Support\TableNames;

/**
 * Builds the daily rollup for one day from its raw entries. Rebuilding a day
 * replaces its rollup rows in one transaction, so re-running is safe and
 * always reflects the entries as they are now.
 *
 * Grain: day, agent, provider and model (the ones that answered, else the
 * ones counted), count method, currency, and the configured primary tag.
 * Accuracy sums use the same rule as LedgerQuery::accuracy(): requests that
 * reported usage from the model that was counted.
 *
 * @internal
 */
final class DailyRollupBuilder
{
    /** Columns written for every group; all expressions are portable SQL. */
    private const string TOTALS = 'count(*) as requests, '
        .'sum(case when used_at is not null then 1 else 0 end) as requests_with_usage, '
        .'sum(case when actual_cost is not null then 1 else 0 end) as priced_requests, '
        ."sum(case when outcome = 'completed' then 1 else 0 end) as completed, "
        ."sum(case when outcome = 'warned' then 1 else 0 end) as warned, "
        ."sum(case when outcome = 'blocked' then 1 else 0 end) as blocked, "
        ."sum(case when outcome = 'failed' then 1 else 0 end) as failed, "
        .'sum(case when streamed then 1 else 0 end) as streamed, '
        .'coalesce(sum(estimated_input_tokens), 0) as estimated_input_tokens, '
        .'coalesce(sum(actual_input_tokens), 0) as actual_input_tokens, '
        .'coalesce(sum(output_tokens), 0) as output_tokens, '
        .'coalesce(sum(cache_read_tokens), 0) as cache_read_tokens, '
        .'coalesce(sum(cache_write_tokens), 0) as cache_write_tokens, '
        .'coalesce(sum(estimated_cost), 0) as estimated_cost, '
        .'coalesce(sum(actual_cost), 0) as actual_cost, '
        .'coalesce(sum(case when actual_cost is null then estimated_cost else 0 end), 0) as estimated_only_cost, '
        .'sum(case when actual_cost is null and estimated_cost is not null then 1 else 0 end) as estimated_only_requests, '
        .'sum(case when actual_cost is not null and cost_approximate then 1 else 0 end) as approximate_requests, '
        .'sum(case when percent_used is not null then 1 else 0 end) as context_samples, '
        .'coalesce(sum(percent_used), 0) as percent_used_sum, '
        .'coalesce(max(percent_used), 0) as percent_used_max, '
        .'sum(case when '.self::ACCURATE.' then 1 else 0 end) as accuracy_samples, '
        .'coalesce(sum(case when '.self::ACCURATE.' then estimated_input_tokens else 0 end), 0) as accuracy_estimated_tokens, '
        .'coalesce(sum(case when '.self::ACCURATE.' then actual_input_tokens else 0 end), 0) as accuracy_actual_tokens, '
        .'coalesce(sum(case when '.self::ACCURATE.' then (case when actual_input_tokens >= estimated_input_tokens '
        .'then actual_input_tokens - estimated_input_tokens else estimated_input_tokens - actual_input_tokens end) else 0 end), 0) '
        .'as accuracy_absolute_error';

    /** Rows that count towards accuracy: an estimate and usage from the model that was counted. */
    private const string ACCURATE = '(estimated_input_tokens is not null and actual_input_tokens is not null '
        .'and model_counted is not null and model_used = model_counted)';

    private const string PROVIDER = 'coalesce(provider_used, provider_counted)';

    private const string MODEL = 'coalesce(model_used, model_counted)';

    /** Joined tag table alias for the primary tag. */
    private const string TAG = 'ledger_primary_tag';

    /**
     * Rebuild one day's rollup rows.
     *
     * @return int The number of rollup rows written.
     */
    public function build(DateTimeInterface|string $day): int
    {
        $date = is_string($day) ? substr($day, 0, 10) : $day->format('Y-m-d');
        $primaryTag = $this->primaryTag();

        $query = Entry::query()->toBase()
            ->where('occurred_on', $date)
            ->selectRaw(self::TOTALS)
            ->selectRaw(self::PROVIDER.' as provider')
            ->selectRaw(self::MODEL.' as model')
            ->addSelect('agent', 'count_method', 'currency')
            ->groupBy('agent', 'count_method', 'currency')
            ->groupByRaw(self::PROVIDER)
            ->groupByRaw(self::MODEL);

        if ($primaryTag !== null) {
            $query->leftJoin(TableNames::tags().' as '.self::TAG, fn (JoinClause $join) => $join
                ->on(self::TAG.'.request_id', '=', TableNames::entries().'.request_id')
                ->where(self::TAG.'.key', '=', $primaryTag))
                ->addSelect(self::TAG.'.value as primary_tag_value')
                ->groupBy(self::TAG.'.value');
        }

        return (new DailyRollup)->getConnection()->transaction(function () use ($query, $date, $primaryTag): int {
            $rows = [];

            foreach ($query->get() as $group) {
                /** @var array<string, mixed> $group */
                $group = (array) $group;

                $rows[] = [
                    ...$group,
                    'date' => $date,
                    'primary_tag_key' => $primaryTag,
                    'primary_tag_value' => $group['primary_tag_value'] ?? null,
                ];
            }

            DailyRollup::query()->where('date', $date)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DailyRollup::query()->insert($chunk);
            }

            return count($rows);
        });
    }

    /**
     * The configured primary tag, if it is allowlisted (otherwise no entry carries it).
     */
    private function primaryTag(): ?string
    {
        $key = config('headroom-ledger.tags.primary_tag');
        $allowed = config('headroom-ledger.tags.allowed', []);

        return is_string($key) && $key !== '' && is_array($allowed) && in_array($key, $allowed, true) ? $key : null;
    }
}
