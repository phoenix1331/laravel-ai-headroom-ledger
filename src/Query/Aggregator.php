<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use LogicException;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroomLedger\Data\AccuracyStat;
use Phoenix1331\AiHeadroomLedger\Data\AccuracyStats;
use Phoenix1331\AiHeadroomLedger\Data\Money;
use Phoenix1331\AiHeadroomLedger\Data\Spend;
use Phoenix1331\AiHeadroomLedger\Data\SpendSummary;
use Phoenix1331\AiHeadroomLedger\Data\TokenTotals;
use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Support\TableNames;

/**
 * Sums filtered raw entries in one GROUP BY query, per group and currency.
 *
 * @internal
 */
final class Aggregator
{
    /** Totals per group and currency; every expression is portable SQL. */
    private const string TOTALS = 'count(*) as requests, '
        .'sum(case when used_at is not null then 1 else 0 end) as with_usage, '
        .'coalesce(sum(estimated_input_tokens), 0) as estimated_input, '
        .'coalesce(sum(actual_input_tokens), 0) as input, '
        .'coalesce(sum(output_tokens), 0) as output, '
        .'coalesce(sum(cache_read_tokens), 0) as cache_read, '
        .'coalesce(sum(cache_write_tokens), 0) as cache_write, '
        .'sum(case when actual_cost is not null then 1 else 0 end) as priced_requests, '
        .'coalesce(sum(actual_cost), 0) as actual_cost, '
        .'sum(case when actual_cost is not null and cost_approximate then 1 else 0 end) as approximate_requests, '
        .'sum(case when actual_cost is null and estimated_cost is not null then 1 else 0 end) as estimate_only_requests, '
        .'coalesce(sum(case when actual_cost is null then estimated_cost else 0 end), 0) as estimate_only_cost';

    /**
     * Accuracy sums. The absolute error avoids abs(a - b), which MySQL rejects
     * when subtracting unsigned integers would go below zero.
     */
    private const string ACCURACY = 'model_counted, count_method, count(*) as samples, '
        .'sum(estimated_input_tokens) as estimated_tokens, '
        .'sum(actual_input_tokens) as actual_tokens, '
        .'sum(case when actual_input_tokens >= estimated_input_tokens '
        .'then actual_input_tokens - estimated_input_tokens '
        .'else estimated_input_tokens - actual_input_tokens end) as absolute_error';

    /** The same totals, summed from daily rollup rows. */
    private const string ROLLUP_TOTALS = 'coalesce(sum(requests), 0) as requests, '
        .'coalesce(sum(requests_with_usage), 0) as with_usage, '
        .'coalesce(sum(estimated_input_tokens), 0) as estimated_input, '
        .'coalesce(sum(actual_input_tokens), 0) as input, '
        .'coalesce(sum(output_tokens), 0) as output, '
        .'coalesce(sum(cache_read_tokens), 0) as cache_read, '
        .'coalesce(sum(cache_write_tokens), 0) as cache_write, '
        .'coalesce(sum(priced_requests), 0) as priced_requests, '
        .'coalesce(sum(actual_cost), 0) as actual_cost, '
        .'coalesce(sum(approximate_requests), 0) as approximate_requests, '
        .'coalesce(sum(estimated_only_requests), 0) as estimate_only_requests, '
        .'coalesce(sum(estimated_only_cost), 0) as estimate_only_cost';

    /** Accuracy sums from daily rollup rows; the rollup model is the counted model for these rows. */
    private const string ROLLUP_ACCURACY = 'model as model_counted, count_method, '
        .'sum(accuracy_samples) as samples, '
        .'sum(accuracy_estimated_tokens) as estimated_tokens, '
        .'sum(accuracy_actual_tokens) as actual_tokens, '
        .'sum(accuracy_absolute_error) as absolute_error';

    /** Joined tag table alias for byTag(). */
    private const string TAG = 'ledger_group_tag';

    /**
     * @param  Builder<Entry>  $rows
     * @param  QueryBuilder|null  $rollups  Filtered daily rollup rows covering the days $rows leaves out.
     * @return array<string, array{key: string|null, spend: SpendSummary, tokens: TokenTotals}> Keyed by group key ('' for null).
     */
    public static function totals(Builder $rows, ?Grouping $grouping = null, ?string $tagKey = null, ?QueryBuilder $rollups = null): array
    {
        $query = $rows->toBase()->selectRaw(self::TOTALS)->addSelect('currency')->groupBy('currency');

        match ($grouping) {
            null => null,
            Grouping::Model => $query->selectRaw('coalesce(model_used, model_counted) as group_key')->groupByRaw('coalesce(model_used, model_counted)'),
            Grouping::Provider => $query->selectRaw('coalesce(provider_used, provider_counted) as group_key')->groupByRaw('coalesce(provider_used, provider_counted)'),
            Grouping::Agent => $query->addSelect('agent as group_key')->groupBy('agent'),
            Grouping::Day => $query->addSelect('occurred_on as group_key')->groupBy('occurred_on'),
            Grouping::Run => $query->addSelect('invocation_id as group_key')->groupBy('invocation_id'),
            Grouping::Tag => $query
                ->leftJoin(TableNames::tags().' as '.self::TAG, fn (JoinClause $join) => $join
                    ->on(self::TAG.'.request_id', '=', TableNames::entries().'.request_id')
                    ->where(self::TAG.'.key', '=', $tagKey))
                ->addSelect(self::TAG.'.value as group_key')
                ->groupBy(self::TAG.'.value'),
        };

        $results = $query->get()->all();

        if ($rollups !== null) {
            $rollups->selectRaw(self::ROLLUP_TOTALS)->addSelect('currency')->groupBy('currency');

            match ($grouping) {
                null => null,
                Grouping::Model => $rollups->addSelect('model as group_key')->groupBy('model'),
                Grouping::Provider => $rollups->addSelect('provider as group_key')->groupBy('provider'),
                Grouping::Agent => $rollups->addSelect('agent as group_key')->groupBy('agent'),
                Grouping::Day => $rollups->addSelect('date as group_key')->groupBy('date'),
                Grouping::Tag => $rollups->addSelect('primary_tag_value as group_key')->groupBy('primary_tag_value'),
                Grouping::Run => throw new LogicException('Runs cannot be read from daily rollups.'),
            };

            array_push($results, ...$rollups->get()->all());
        }

        $groups = [];

        foreach ($results as $row) {
            /** @var array<string, mixed> $row */
            $row = (array) $row;
            $key = $grouping === null ? null : self::key($row['group_key'] ?? null, $grouping);
            $groups[$key ?? ''] = self::merge($groups[$key ?? ''] ?? ['key' => $key, 'spend' => new SpendSummary, 'tokens' => new TokenTotals], $row);
        }

        return $groups;
    }

    /**
     * Estimate against actual input tokens, per counted model and count method,
     * for requests that reported usage from the model that was counted.
     *
     * @param  Builder<Entry>  $rows
     * @param  QueryBuilder|null  $rollups  Filtered daily rollup rows covering the days $rows leaves out.
     */
    public static function accuracy(Builder $rows, ?QueryBuilder $rollups = null): AccuracyStats
    {
        $results = $rows->toBase()
            ->selectRaw(self::ACCURACY)
            ->whereNotNull('estimated_input_tokens')
            ->whereNotNull('actual_input_tokens')
            ->whereNotNull('model_counted')
            ->whereColumn('model_used', 'model_counted')
            ->groupBy('model_counted', 'count_method')
            ->get()
            ->all();

        if ($rollups !== null) {
            array_push($results, ...$rollups
                ->selectRaw(self::ROLLUP_ACCURACY)
                ->where('accuracy_samples', '>', 0)
                ->groupBy('model', 'count_method')
                ->get()
                ->all());
        }

        $stats = [];

        foreach ($results as $row) {
            /** @var array<string, mixed> $row */
            $row = (array) $row;
            $method = $row['count_method'] ?? null;

            $stat = new AccuracyStat(
                is_scalar($row['model_counted'] ?? null) ? (string) $row['model_counted'] : null,
                is_string($method) ? CountMethod::tryFrom($method) : null,
                self::int($row, 'samples'),
                self::int($row, 'estimated_tokens'),
                self::int($row, 'actual_tokens'),
                self::int($row, 'absolute_error'),
            );
            $key = $stat->model.'|'.$stat->countMethod?->value;
            $stats[$key] = isset($stats[$key]) ? $stats[$key]->plus($stat) : $stat;
        }

        return new AccuracyStats(array_values($stats));
    }

    /**
     * @param  array{key: string|null, spend: SpendSummary, tokens: TokenTotals}  $group
     * @param  array<string, mixed>  $row
     * @return array{key: string|null, spend: SpendSummary, tokens: TokenTotals}
     */
    private static function merge(array $group, array $row): array
    {
        $currency = is_string($row['currency'] ?? null) ? $row['currency'] : null;
        $priced = self::int($row, 'priced_requests');
        $estimateOnly = self::int($row, 'estimate_only_requests');
        $spend = $group['spend'];

        $actual = $currency !== null && $priced > 0
            ? [new Money(self::int($row, 'actual_cost'), $currency, approximate: self::int($row, 'approximate_requests') > 0)]
            : [];
        $estimated = $currency !== null && $estimateOnly > 0
            ? [new Money(self::int($row, 'estimate_only_cost'), $currency, estimated: true)]
            : [];

        return [
            'key' => $group['key'],
            'spend' => new SpendSummary(
                new Spend([...$spend->total->all(), ...$actual, ...$estimated]),
                new Spend([...$spend->actual->all(), ...$actual]),
                new Spend([...$spend->estimatedOnly->all(), ...$estimated]),
                $spend->requests + self::int($row, 'requests'),
                $spend->requestsWithUsage + self::int($row, 'with_usage'),
                $spend->estimateOnlyRequests + $estimateOnly,
            ),
            'tokens' => $group['tokens']->plus(new TokenTotals(
                self::int($row, 'estimated_input'),
                self::int($row, 'input'),
                self::int($row, 'output'),
                self::int($row, 'cache_read'),
                self::int($row, 'cache_write'),
                self::int($row, 'requests'),
                self::int($row, 'with_usage'),
            )),
        ];
    }

    private static function key(mixed $value, Grouping $grouping): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $key = is_scalar($value) ? (string) $value : null;

        // Databases return dates as "Y-m-d" or with a midnight time.
        return $grouping === Grouping::Day && $key !== null ? substr($key, 0, 10) : $key;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
