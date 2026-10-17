<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Query;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Phoenix1331\AiHeadroomLedger\Data\AccuracyStats;
use Phoenix1331\AiHeadroomLedger\Data\LedgerEntries;
use Phoenix1331\AiHeadroomLedger\Data\SpendSummary;
use Phoenix1331\AiHeadroomLedger\Data\TokenTotals;
use Phoenix1331\AiHeadroomLedger\Enums\Outcome;
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
     * Matching requests, newest first.
     */
    public function entries(?int $limit = null): LedgerEntries
    {
        $rows = $this->rows()
            ->with('tags')
            ->orderByRaw('coalesce(counted_at, used_at) desc')
            ->orderByDesc('request_id')
            ->when($limit !== null, fn (Builder $query) => $query->limit((int) $limit))
            ->get();

        return new LedgerEntries($rows->map(EntryMapper::toEntry(...)));
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
        return Aggregator::totals($this->rows())['']['spend'] ?? new SpendSummary;
    }

    /**
     * Token totals for the matching requests. Actual figures cover only
     * requests whose usage was reported.
     */
    public function tokens(): TokenTotals
    {
        return Aggregator::totals($this->rows())['']['tokens'] ?? new TokenTotals;
    }

    /**
     * How close Headroom's estimates came to reported input tokens, per counted
     * model and count method. Only requests that reported usage from the model
     * that was counted take part; failover answers are left out.
     */
    public function accuracy(): AccuracyStats
    {
        return Aggregator::accuracy($this->rows());
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

        return new GroupedQuery(fn (): Builder => $this->rows(), $range, $grouping, $tagKey);
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
