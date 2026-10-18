<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Phoenix1331\AiHeadroomLedger\Data\LedgerEntry;
use Phoenix1331\AiHeadroomLedger\Data\LedgerPage;
use Phoenix1331\AiHeadroomLedger\Database\Factories\LedgerEntryFactory;
use Phoenix1331\AiHeadroomLedger\Enums\EntrySort;
use Phoenix1331\AiHeadroomLedger\Facades\Ledger;
use Phoenix1331\AiHeadroomLedger\Models\Entry;

/**
 * @return list<string>
 */
function pageIds(LedgerPage $page): array
{
    return array_map(fn (LedgerEntry $entry): string => $entry->requestId, $page->entries->all());
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-17 12:00:00');
});

it('returns the first page with the total across every page', function (): void {
    LedgerEntryFactory::new()->count(30)->create();

    $page = Ledger::query()->paginate();

    expect($page)->toBeInstanceOf(LedgerPage::class)
        ->total->toBe(30)
        ->page->toBe(1)
        ->perPage->toBe(25)
        ->and($page->entries)->toHaveCount(25)
        ->and($page->lastPage())->toBe(2)
        ->and($page->hasMorePages())->toBeTrue()
        ->and($page->isEmpty())->toBeFalse();
});

it('returns a partial last page', function (): void {
    LedgerEntryFactory::new()->count(7)->create();

    $page = Ledger::query()->paginate(perPage: 3, page: 3);

    expect($page->entries)->toHaveCount(1)
        ->and($page->total)->toBe(7)
        ->and($page->lastPage())->toBe(3)
        ->and($page->hasMorePages())->toBeFalse();
});

it('returns an empty page past the end with the right total', function (): void {
    LedgerEntryFactory::new()->count(4)->create();

    $page = Ledger::query()->paginate(perPage: 3, page: 5);

    expect($page->isEmpty())->toBeTrue()
        ->and($page->total)->toBe(4)
        ->and($page->page)->toBe(5)
        ->and($page->lastPage())->toBe(2)
        ->and($page->hasMorePages())->toBeFalse();
});

it('has one empty page when nothing matches', function (): void {
    $page = Ledger::query()->paginate();

    expect($page->isEmpty())->toBeTrue()
        ->and($page->total)->toBe(0)
        ->and($page->lastPage())->toBe(1)
        ->and($page->hasMorePages())->toBeFalse();
});

it('counts and pages only the filtered requests', function (): void {
    LedgerEntryFactory::new()->count(5)->forAgent('App\\Ai\\Agents\\SupportAgent')->withTags(['tenant_id' => 42])->create();
    LedgerEntryFactory::new()->count(5)->forAgent('App\\Ai\\Agents\\SupportAgent')->withTags(['tenant_id' => 7])->create();
    LedgerEntryFactory::new()->count(5)->forAgent('App\\Ai\\Agents\\ResearchAgent')->withTags(['tenant_id' => 42])->create();

    $query = Ledger::query()->forAgent('App\\Ai\\Agents\\SupportAgent')->forTag('tenant_id', 42);
    $page = $query->paginate(perPage: 2, page: 3);

    expect($page->total)->toBe(5)
        ->and($page->entries)->toHaveCount(1)
        ->and($page->entries->first()?->tags)->toBe(['tenant_id' => '42'])
        ->and($page->entries->first()?->agent)->toBe('App\\Ai\\Agents\\SupportAgent');
});

it('pages in the entries() order without overlap or gaps', function (EntrySort $sort, bool $descending): void {
    // Few distinct values and some nulls, so most of the order comes from the tie-break.
    foreach (range(1, 11) as $i) {
        $id = sprintf('req-%02d', $i);
        LedgerEntryFactory::new()->create(['request_id' => $id]);
        Entry::query()->whereKey($id)->update($i % 4 === 0
            ? ['percent_used' => null, 'output_tokens' => null, 'actual_cost' => null, 'estimated_cost' => null, 'actual_input_tokens' => null, 'estimated_input_tokens' => null, 'counted_at' => null, 'used_at' => null]
            : ['percent_used' => $i % 3, 'output_tokens' => $i % 3, 'actual_cost' => $i % 3, 'actual_input_tokens' => $i % 3, 'counted_at' => '2026-10-17 0'.($i % 3).':00:00']);
    }

    $query = Ledger::query()->orderBy($sort, $descending);
    $paged = [...pageIds($query->paginate(4, 1)), ...pageIds($query->paginate(4, 2)), ...pageIds($query->paginate(4, 3))];
    $all = array_map(fn (LedgerEntry $entry): string => $entry->requestId, $query->entries()->all());

    expect($paged)->toBe($all)
        ->and(array_unique($paged))->toHaveCount(11);
})->with(EntrySort::cases())->with([
    'descending' => true,
    'ascending' => false,
]);

it('rejects fewer than one entry per page', function (): void {
    Ledger::query()->paginate(perPage: 0);
})->throws(InvalidArgumentException::class);

it('rejects a page number below 1', function (): void {
    Ledger::query()->paginate(page: 0);
})->throws(InvalidArgumentException::class);
