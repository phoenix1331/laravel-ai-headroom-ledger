<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Query;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Phoenix1331\AiHeadroomLedger\Data\Breakdown;
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
     * @param  Closure(): Builder<Entry>  $rows
     * @param  array{0: string, 1: string}|null  $range  Inclusive "Y-m-d" days of the date filter.
     */
    public function __construct(
        private Closure $rows,
        private ?array $range,
        private Grouping $grouping,
        private ?string $tagKey = null,
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
     * @template TValue of SpendSummary|TokenTotals
     *
     * @param  'spend'|'tokens'  $part
     * @param  TValue  $empty
     * @return Breakdown<TValue>
     */
    private function breakdown(string $part, SpendSummary|TokenTotals $empty): Breakdown
    {
        $groups = [];

        foreach (Aggregator::totals(($this->rows)(), $this->grouping, $this->tagKey) as $totals) {
            /** @var TValue $value */
            $value = $totals[$part];
            $groups[$totals['key'] ?? ''] = new Group($totals['key'], $value);
        }

        foreach ($this->emptyDays() as $day) {
            $groups[$day] ??= new Group($day, $empty);
        }

        return new Breakdown(array_values($groups));
    }

    /**
     * @return list<string>
     */
    private function emptyDays(): array
    {
        if ($this->grouping !== Grouping::Day || $this->range === null) {
            return [];
        }

        $days = [];

        for ($day = CarbonImmutable::parse($this->range[0]); $day->toDateString() <= $this->range[1]; $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }
}
