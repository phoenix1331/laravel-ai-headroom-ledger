<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Data;

/**
 * One page of matching entries from LedgerQuery::paginate(), with the total
 * across every page. Wrap it in your framework's paginator if you need one.
 */
final readonly class LedgerPage
{
    public function __construct(
        public LedgerEntries $entries,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    /**
     * The number of the last page; 1 when nothing matches.
     */
    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasMorePages(): bool
    {
        return $this->page < $this->lastPage();
    }

    public function isEmpty(): bool
    {
        return $this->entries->isEmpty();
    }
}
