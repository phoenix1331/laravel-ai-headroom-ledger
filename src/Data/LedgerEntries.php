<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Data;

use ArrayIterator;
use Countable;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;

/**
 * A typed, read-only list of ledger entries.
 *
 * @implements IteratorAggregate<int, LedgerEntry>
 */
final readonly class LedgerEntries implements Countable, IteratorAggregate
{
    /** @var list<LedgerEntry> */
    private array $entries;

    /**
     * @param  iterable<LedgerEntry>  $entries
     */
    public function __construct(iterable $entries = [])
    {
        $list = [];

        foreach ($entries as $entry) {
            $list[] = $entry;
        }

        $this->entries = $list;
    }

    /**
     * @return list<LedgerEntry>
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function first(): ?LedgerEntry
    {
        return $this->entries[0] ?? null;
    }

    public function last(): ?LedgerEntry
    {
        return $this->entries === [] ? null : $this->entries[count($this->entries) - 1];
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function tokens(): TokenTotals
    {
        return array_reduce(
            $this->entries,
            fn (TokenTotals $total, LedgerEntry $entry): TokenTotals => $total->plus($entry->tokens()),
            new TokenTotals,
        );
    }

    /**
     * Actual cost where usage was reported, else the estimate, grouped by currency.
     */
    public function spend(): Spend
    {
        return new Spend(array_filter(array_map(fn (LedgerEntry $entry): ?Money => $entry->cost(), $this->entries)));
    }

    /**
     * A Laravel collection of the entries, for filtering, grouping and the like.
     *
     * @return Collection<int, LedgerEntry>
     */
    public function collect(): Collection
    {
        return new Collection($this->entries);
    }

    /**
     * @return Traversable<int, LedgerEntry>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->entries);
    }
}
