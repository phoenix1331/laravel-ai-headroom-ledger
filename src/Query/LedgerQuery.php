<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Query;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroomLedger\Data\AccuracyStats;
use Phoenix1331\AiHeadroomLedger\Data\ContextUsage;
use Phoenix1331\AiHeadroomLedger\Data\LedgerEntries;
use Phoenix1331\AiHeadroomLedger\Data\LedgerEntry;
use Phoenix1331\AiHeadroomLedger\Data\LedgerPage;
use Phoenix1331\AiHeadroomLedger\Data\RunSummary;
use Phoenix1331\AiHeadroomLedger\Data\SpendSummary;
use Phoenix1331\AiHeadroomLedger\Data\TokenTotals;
use Phoenix1331\AiHeadroomLedger\Enums\EntrySort;
use Phoenix1331\AiHeadroomLedger\Enums\Outcome;
use Phoenix1331\AiHeadroomLedger\Models\DailyRollup;
use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Support\TableNames;
use Phoenix1331\AiHeadroomLedger\Support\TagValue;

/**
 * Filters over the ledger. Immutable: every filter returns a new query, so a
 * base query can be shared. Dates are whole days, in the app timezone, by
 * the date each request was counted (the same date daily rollups use).
 *
 *     Ledger::query()->lastDays(7)->forAgent(SupportAgent::class)->nearLimit(80)->entries();
 */
final class LedgerQuery
{
    private ?string $from = null;

    private ?string $to = null;

    private ?string $agent = null;

    private ?string $provider = null;

    private ?string $model = null;

    /** @var array<string, string> */
    private array $tags = [];

    private ?float $minPercent = null;

    /** @var list<Outcome> */
    private array $outcomes = [];

    /** @var list<CountMethod> */
    private array $countMethods = [];

    private EntrySort $sort = EntrySort::CountedAt;

    private bool $descending = true;

    /**
     * Requests counted on any day from $from to $to, inclusive.
     */
    public function between(DateTimeInterface $from, DateTimeInterface $to): self
    {
        $from = self::day($from);
        $to = self::day($to);

        if ($from > $to) {
            throw new InvalidArgumentException("The range starts ({$from}) after it ends ({$to}).");
        }

        return $this->with(function (self $query) use ($from, $to): void {
            $query->from = $from;
            $query->to = $to;
        });
    }

    /**
     * Today and the $days - 1 days before it.
     */
    public function lastDays(int $days): self
    {
        if ($days < 1) {
            throw new InvalidArgumentException('lastDays() needs at least one day.');
        }

        $today = CarbonImmutable::now();

        return $this->between($today->subDays($days - 1), $today);
    }

    public function forAgent(string $agent): self
    {
        return $this->with(fn (self $query) => $query->agent = $agent);
    }

    /**
     * Requests answered by this provider, or counted against it when no usage was reported.
     */
    public function forProvider(string $provider): self
    {
        return $this->with(fn (self $query) => $query->provider = $provider);
    }

    /**
     * Requests answered by this model, or counted against it when no usage was reported.
     */
    public function forModel(string $model): self
    {
        return $this->with(fn (self $query) => $query->model = $model);
    }

    /**
     * Requests counted with any of these methods. Requests with no recorded
     * method (a warning or failure alone) never match.
     */
    public function forCountMethod(CountMethod ...$methods): self
    {
        return $this->with(fn (self $query) => $query->countMethods = array_values(array_unique($methods, SORT_REGULAR)));
    }

    /**
     * Requests carrying this context tag. Chain it to require several tags.
     */
    public function forTag(string $key, string|int|float|bool $value): self
    {
        return $this->with(fn (self $query) => $query->tags[$key] = TagValue::from($value));
    }

    /**
     * Requests whose estimate used at least this percentage of the context window.
     */
    public function nearLimit(float $percent = 80.0): self
    {
        return $this->with(fn (self $query) => $query->minPercent = $percent);
    }

    /**
     * Requests that hit a limit: blocked, and by default also warned (allowed through in report mode).
     */
    public function blocked(bool $includeWarned = true): self
    {
        return $includeWarned
            ? $this->withOutcome(Outcome::Blocked, Outcome::Warned)
            : $this->withOutcome(Outcome::Blocked);
    }

    public function withOutcome(Outcome ...$outcomes): self
    {
        return $this->with(fn (self $query) => $query->outcomes = array_values(array_unique($outcomes, SORT_REGULAR)));
    }

    /**
     * The order entries() returns requests in; newest first by default.
     * Requests without the figure sort last either way, and ties fall back
     * to the request ID, so pages never overlap.
     */
    public function orderBy(EntrySort $sort, bool $descending = true): self
    {
        return $this->with(function (self $query) use ($sort, $descending): void {
            $query->sort = $sort;
            $query->descending = $descending;
        });
    }

    /**
     * Matching requests, newest first unless orderBy() says otherwise.
     */
    public function entries(?int $limit = null): LedgerEntries
    {
        $rows = $this->sorted($this->rows()->with('tags'))
            ->when($limit !== null, fn (Builder $query) => $query->limit((int) $limit))
            ->get();

        return new LedgerEntries($rows->map(EntryMapper::toEntry(...)));
    }

    /**
     * One page of matching requests, in the entries() order, with the total
     * across every page. A page past the end is empty.
     */
    public function paginate(int $perPage = 25, int $page = 1): LedgerPage
    {
        if ($perPage < 1 || $page < 1) {
            throw new InvalidArgumentException('paginate() needs at least one entry per page and a page number of at least 1.');
        }

        $rows = $this->sorted($this->rows()->with('tags'))->forPage($page, $perPage)->get();

        return new LedgerPage(new LedgerEntries($rows->map(EntryMapper::toEntry(...))), $this->count(), $page, $perPage);
    }

    /**
     * One request by its Headroom request ID, or null if it is not in the
     * ledger or does not match every filter. Use this rather than
     * Ledger::find() to keep one tenant from reading another's request:
     *
     *     Ledger::query()->forTag('tenant_id', $tenant->id)->find($requestId);
     */
    public function find(string $requestId): ?LedgerEntry
    {
        $row = $this->rows()->with('tags')->find($requestId);

        return $row === null ? null : EntryMapper::toEntry($row);
    }

    /**
     * The steps of one agent run that match every filter, in order, with
     * totals; null if none match. A date filter keeps only the steps inside
     * the range, so a run that crosses midnight at the range edge comes back
     * partial.
     */
    public function run(string $invocationId): ?RunSummary
    {
        $rows = $this->rows()
            ->with('tags')
            ->where('invocation_id', $invocationId)
            ->orderByRaw('coalesce(counted_at, used_at)')
            ->orderBy('request_id')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        return RunSummary::of($invocationId, new LedgerEntries($rows->map(EntryMapper::toEntry(...))));
    }

    public function count(): int
    {
        return $this->rows()->count();
    }

    /**
     * What the matching requests cost: actual where usage was reported, else
     * the estimate, with both parts visible and every amount per currency.
     */
    public function spend(): SpendSummary
    {
        return Aggregator::totals(...$this->plan())['']['spend'] ?? new SpendSummary;
    }

    /**
     * Token totals for the matching requests. Actual figures cover only
     * requests whose usage was reported.
     */
    public function tokens(): TokenTotals
    {
        return Aggregator::totals(...$this->plan())['']['tokens'] ?? new TokenTotals;
    }

    /**
     * Context window usage and limit outcomes for the matching requests.
     */
    public function context(): ContextUsage
    {
        return Aggregator::totals(...$this->plan())['']['context'] ?? new ContextUsage;
    }

    /**
     * How close Headroom's estimates came to reported input tokens, per counted
     * model and count method. Only requests that reported usage from the model
     * that was counted take part; failover answers are left out.
     */
    public function accuracy(): AccuracyStats
    {
        [$rows, , , $rollups] = $this->plan();

        return Aggregator::accuracy($rows, $rollups);
    }

    /**
     * Group by the model that answered, else the one counted.
     */
    public function byModel(): GroupedQuery
    {
        return $this->grouped(Grouping::Model);
    }

    /**
     * Group by the provider that answered, else the one counted.
     */
    public function byProvider(): GroupedQuery
    {
        return $this->grouped(Grouping::Provider);
    }

    public function byAgent(): GroupedQuery
    {
        return $this->grouped(Grouping::Agent);
    }

    /**
     * Group by the day each request was counted, oldest first.
     */
    public function byDay(): GroupedQuery
    {
        return $this->grouped(Grouping::Day);
    }

    /**
     * Group by agent run (SDK invocation) rather than by provider call.
     */
    public function byRun(): GroupedQuery
    {
        return $this->grouped(Grouping::Run);
    }

    /**
     * Group by the value of one context tag; requests without it form the null group.
     */
    public function byTag(string $key): GroupedQuery
    {
        return $this->grouped(Grouping::Tag, $key);
    }

    private function grouped(Grouping $grouping, ?string $tagKey = null): GroupedQuery
    {
        $range = $this->from === null || $this->to === null ? null : [$this->from, $this->to];

        return new GroupedQuery(fn (): array => $this->plan($grouping, $tagKey), $range);
    }

    /**
     * Where an aggregate reads from: daily rollups for closed days they cover,
     * raw entries for every other day (today, days not yet rolled up, or days
     * rolled up with a different primary tag). Raw only when rollups are off
     * or cannot answer the filters or grouping.
     *
     * @return array{0: Builder<Entry>, 1: Grouping|null, 2: string|null, 3: QueryBuilder|null}
     */
    private function plan(?Grouping $grouping = null, ?string $tagKey = null): array
    {
        $primaryTag = TableNames::primaryTag();
        $usesTag = $this->tags !== [] || $grouping === Grouping::Tag;

        $answerable = config()->boolean('headroom-ledger.rollup.enabled', true)
            && $this->minPercent === null
            && $this->outcomes === []
            && $grouping !== Grouping::Run
            && ($this->tags === [] || (count($this->tags) === 1 && array_key_first($this->tags) === $primaryTag))
            && ($grouping !== Grouping::Tag || $tagKey === $primaryTag);

        if (! $answerable) {
            return [$this->rows(), $grouping, $tagKey, null];
        }

        $tagKeyOfDay = $usesTag ? $primaryTag : null;

        $rows = $this->rows()->whereNotExists(fn (QueryBuilder $days) => self::coveredDays($days, TableNames::entries().'.occurred_on', $tagKeyOfDay));
        $rollups = $this->rollupRows()->whereExists(fn (QueryBuilder $days) => self::coveredDays($days, TableNames::daily().'.date', $tagKeyOfDay));

        return [$rows, $grouping, $tagKey, $rollups];
    }

    /**
     * Closed days the rollups cover, built with the primary tag the query needs (if any).
     */
    private static function coveredDays(QueryBuilder $days, string $dateColumn, ?string $tagKey): void
    {
        $table = TableNames::rollupDays();

        $days->from($table)
            ->whereColumn($table.'.date', $dateColumn)
            ->where($table.'.date', '<', CarbonImmutable::today()->toDateString())
            ->when($tagKey !== null, fn (QueryBuilder $query) => $query->where($table.'.primary_tag_key', $tagKey));
    }

    /**
     * Daily rollup rows matching the filters rollups can answer.
     */
    private function rollupRows(): QueryBuilder
    {
        return DailyRollup::query()->toBase()
            ->when($this->from !== null, fn (QueryBuilder $query) => $query->whereBetween('date', [$this->from, $this->to]))
            ->when($this->agent !== null, fn (QueryBuilder $query) => $query->where('agent', $this->agent))
            ->when($this->provider !== null, fn (QueryBuilder $query) => $query->where('provider', $this->provider))
            ->when($this->model !== null, fn (QueryBuilder $query) => $query->where('model', $this->model))
            ->when($this->countMethods !== [], fn (QueryBuilder $query) => $query->whereIn('count_method', $this->countMethods))
            ->tap(function (QueryBuilder $query): void {
                foreach ($this->tags as $key => $value) {
                    $query->where('primary_tag_key', $key)->where('primary_tag_value', $value);
                }
            });
    }

    /**
     * The filtered raw entries. Private, so no Eloquent builder reaches consumers.
     *
     * @return Builder<Entry>
     */
    private function rows(): Builder
    {
        $entries = TableNames::entries();

        return Entry::query()
            ->when($this->from !== null, fn (Builder $query) => $query->whereBetween('occurred_on', [$this->from, $this->to]))
            ->when($this->agent !== null, fn (Builder $query) => $query->where('agent', $this->agent))
            ->when($this->provider !== null, fn (Builder $query) => self::usedElseCounted($query, 'provider', (string) $this->provider))
            ->when($this->model !== null, fn (Builder $query) => self::usedElseCounted($query, 'model', (string) $this->model))
            ->when($this->countMethods !== [], fn (Builder $query) => $query->whereIn('count_method', $this->countMethods))
            ->when($this->minPercent !== null, fn (Builder $query) => $query->where('percent_used', '>=', $this->minPercent))
            ->when($this->outcomes !== [], fn (Builder $query) => $query->whereIn('outcome', $this->outcomes))
            ->tap(function (Builder $query) use ($entries): void {
                foreach ($this->tags as $key => $value) {
                    $query->whereExists(fn (QueryBuilder $tags) => $tags
                        ->from(TableNames::tags())
                        ->whereColumn(TableNames::tags().'.request_id', $entries.'.request_id')
                        ->where('key', $key)
                        ->where('value', $value));
                }
            });
    }

    /**
     * Nulls last on every database (PostgreSQL puts them first on desc by
     * default), then the request ID so the order is total.
     *
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    private function sorted(Builder $query): Builder
    {
        $column = match ($this->sort) {
            EntrySort::CountedAt => 'coalesce(counted_at, used_at)',
            EntrySort::PercentUsed => 'percent_used',
            EntrySort::Cost => 'coalesce(actual_cost, estimated_cost)',
            EntrySort::InputTokens => 'coalesce(actual_input_tokens, estimated_input_tokens)',
            EntrySort::OutputTokens => 'output_tokens',
        };
        $direction = $this->descending ? 'desc' : 'asc';

        return $query
            ->orderByRaw("case when {$column} is null then 1 else 0 end")
            ->orderByRaw("{$column} {$direction}")
            ->orderBy('request_id', $direction);
    }

    /**
     * @param  Builder<Entry>  $query
     */
    private static function usedElseCounted(Builder $query, string $column, string $value): void
    {
        $query->where(fn (Builder $query) => $query
            ->where("{$column}_used", $value)
            ->orWhere(fn (Builder $query) => $query->whereNull("{$column}_used")->where("{$column}_counted", $value)));
    }

    /**
     * @param  callable(self): mixed  $change
     */
    private function with(callable $change): self
    {
        $query = clone $this;
        $change($query);

        return $query;
    }

    private static function day(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->setTimezone(config()->string('app.timezone', 'UTC'))->toDateString();
    }
}
