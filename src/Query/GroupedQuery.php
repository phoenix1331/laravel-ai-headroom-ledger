<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Query;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Phoenix1331\AiHeadroomLedger\Data\AccuracyStats;
use Phoenix1331\AiHeadroomLedger\Data\Breakdown;
use Phoenix1331\AiHeadroomLedger\Data\ContextUsage;
use Phoenix1331\AiHeadroomLedger\Data\Group;
use Phoenix1331\AiHeadroomLedger\Data\SpendSummary;
use Phoenix1331\AiHeadroomLedger\Data\TokenTotals;
use Phoenix1331\AiHeadroomLedger\Models\Entry;

/**
 * A filtered ledger query grouped by model, provider, agent, day, run or tag.
 * Apply filters before grouping:
 *
 *     Ledger::query()->lastDays(7)->forAgent(SupportAgent::class)->byDay()->spend();
 */
final readonly class GroupedQuery
{
    /**
     * @internal Built by LedgerQuery's by*() methods.
     *
     * @param  Closure(): array{0: Builder<Entry>, 1: Grouping|null, 2: string|null, 3: QueryBuilder|null}  $plan  Where to read each day from.
     * @param  array{0: string, 1: string}|null  $range  Inclusive "Y-m-d" days of the date filter.
     */
    public function __construct(
        private Closure $plan,
        private ?array $range,
    ) {}

    /**
     * Spend per group. With byDay() and a date range, days without requests are included with no spend.
     *
     * @return Breakdown<SpendSummary>
     */
    public function spend(): Breakdown
    {
        return $this->breakdown('spend', new SpendSummary);
    }

    /**
     * Tokens per group. With byDay() and a date range, days without requests are included with zero tokens.
     *
     * @return Breakdown<TokenTotals>
     */
    public function tokens(): Breakdown
    {
        return $this->breakdown('tokens', new TokenTotals);
    }

    /**
     * Context window usage and limit outcomes per group.
     *
     * @return Breakdown<ContextUsage>
     */
    public function context(): Breakdown
    {
        return $this->breakdown('context', new ContextUsage);
    }

    /**
     * Estimate accuracy per group, by counted model and count method. With
     * byDay() and a date range, days without samples are included and empty.
     *
     * @return Breakdown<AccuracyStats>
     */
    public function accuracy(): Breakdown
    {
        $plan = ($this->plan)();
        $groups = [];

        foreach (Aggregator::accuracy(...$plan) as $index => $group) {
            $groups[$index] = new Group($group['key'], $group['accuracy']);
        }

        return $this->withEmptyDays($groups, $plan[1], new AccuracyStats);
    }

    /**
     * @template TValue of SpendSummary|TokenTotals|ContextUsage
     *
     * @param  'spend'|'tokens'|'context'  $part
     * @param  TValue  $empty
     * @return Breakdown<TValue>
     */
    private function breakdown(string $part, SpendSummary|TokenTotals|ContextUsage $empty): Breakdown
    {
        $plan = ($this->plan)();
        $groups = [];

        foreach (Aggregator::totals(...$plan) as $index => $totals) {
            /** @var TValue $value */
            $value = $totals[$part];
            $groups[$index] = new Group($totals['key'], $value);
        }

        return $this->withEmptyDays($groups, $plan[1], $empty);
    }

    /**
     * @template TValue of SpendSummary|TokenTotals|ContextUsage|AccuracyStats
     *
     * @param  array<string, Group<TValue>>  $groups  Keyed by group key ('' for null).
     * @param  TValue  $empty
     * @return Breakdown<TValue>
     */
    private function withEmptyDays(array $groups, ?Grouping $grouping, SpendSummary|TokenTotals|ContextUsage|AccuracyStats $empty): Breakdown
    {
        foreach ($this->emptyDays($grouping) as $day) {
            $groups[$day] ??= new Group($day, $empty);
        }

        return new Breakdown(array_values($groups));
    }

    /**
     * @return list<string>
     */
    private function emptyDays(?Grouping $grouping): array
    {
        if ($grouping !== Grouping::Day || $this->range === null) {
            return [];
        }

        $days = [];

        for ($day = CarbonImmutable::parse($this->range[0]); $day->toDateString() <= $this->range[1]; $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }
}
