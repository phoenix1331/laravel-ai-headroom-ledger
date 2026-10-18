<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Phoenix1331\AiHeadroomLedger\Data\LedgerEntry;
use Phoenix1331\AiHeadroomLedger\Database\Factories\LedgerEntryFactory;
use Phoenix1331\AiHeadroomLedger\Enums\EntrySort;
use Phoenix1331\AiHeadroomLedger\Facades\Ledger;
use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Query\LedgerQuery;

/**
 * An entry with exactly these column values, whatever the factory derived.
 *
 * @param  array<string, mixed>  $columns
 */
function entryWith(string $requestId, array $columns): void
{
    LedgerEntryFactory::new()->create(['request_id' => $requestId]);
    Entry::query()->whereKey($requestId)->update($columns);
}

/**
 * @return list<string>
 */
function sortedIds(LedgerQuery $query): array
{
    return array_map(fn (LedgerEntry $entry): string => $entry->requestId, $query->entries()->all());
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-17 12:00:00');
});

/*
 * For each sort: "low" < "mid" < "high", where "high" only has the fallback
 * figure (the estimate, or the usage time), and "none" has neither.
 */
dataset('sorts', [
    'counted at' => [EntrySort::CountedAt, [
        'low' => ['counted_at' => '2026-10-17 09:00:00', 'used_at' => '2026-10-17 11:30:00'],
        'mid' => ['counted_at' => '2026-10-17 10:00:00'],
        'high' => ['counted_at' => null, 'used_at' => '2026-10-17 11:00:00'],
        'none' => ['counted_at' => null, 'used_at' => null],
    ]],
    'percent used' => [EntrySort::PercentUsed, [
        'low' => ['percent_used' => 10],
        'mid' => ['percent_used' => 20.5],
        'high' => ['percent_used' => 130],
        'none' => ['percent_used' => null],
    ]],
    'cost' => [EntrySort::Cost, [
        'low' => ['actual_cost' => 10, 'estimated_cost' => 999],
        'mid' => ['actual_cost' => 20, 'estimated_cost' => 1],
        'high' => ['actual_cost' => null, 'estimated_cost' => 30],
        'none' => ['actual_cost' => null, 'estimated_cost' => null],
    ]],
    'input tokens' => [EntrySort::InputTokens, [
        'low' => ['actual_input_tokens' => 10, 'estimated_input_tokens' => 999],
        'mid' => ['actual_input_tokens' => 20, 'estimated_input_tokens' => 1],
        'high' => ['actual_input_tokens' => null, 'estimated_input_tokens' => 30],
        'none' => ['actual_input_tokens' => null, 'estimated_input_tokens' => null],
    ]],
    'output tokens' => [EntrySort::OutputTokens, [
        'low' => ['output_tokens' => 10],
        'mid' => ['output_tokens' => 20],
        'high' => ['output_tokens' => 30],
        'none' => ['output_tokens' => null],
    ]],
]);

it('sorts descending with requests lacking the figure last', function (EntrySort $sort, array $rows): void {
    foreach (['mid', 'none', 'low', 'high'] as $id) {
        entryWith($id, $rows[$id]);
    }

    expect(sortedIds(Ledger::query()->orderBy($sort)))->toBe(['high', 'mid', 'low', 'none']);
})->with('sorts');

it('sorts ascending with requests lacking the figure still last', function (EntrySort $sort, array $rows): void {
    foreach (['mid', 'none', 'low', 'high'] as $id) {
        entryWith($id, $rows[$id]);
    }

    expect(sortedIds(Ledger::query()->orderBy($sort, descending: false)))->toBe(['low', 'mid', 'high', 'none']);
})->with('sorts');

it('breaks ties by request ID in the same direction', function (): void {
    foreach (['b', 'c', 'a'] as $id) {
        entryWith($id, ['output_tokens' => 50]);
    }

    expect(sortedIds(Ledger::query()->orderBy(EntrySort::OutputTokens)))->toBe(['c', 'b', 'a'])
        ->and(sortedIds(Ledger::query()->orderBy(EntrySort::OutputTokens, descending: false)))->toBe(['a', 'b', 'c']);
});

it('keeps newest first by default', function (): void {
    entryWith('old', ['counted_at' => '2026-10-15 09:00:00']);
    entryWith('undated', ['counted_at' => null, 'used_at' => null]);
    entryWith('new', ['counted_at' => '2026-10-17 09:00:00']);

    expect(sortedIds(Ledger::query()))->toBe(['new', 'old', 'undated'])
        ->and(sortedIds(Ledger::query()))->toBe(sortedIds(Ledger::query()->orderBy(EntrySort::CountedAt)));
});

it('applies the order to a limited list and leaves the original query alone', function (): void {
    entryWith('small', ['output_tokens' => 10, 'counted_at' => '2026-10-17 11:00:00']);
    entryWith('large', ['output_tokens' => 90, 'counted_at' => '2026-10-17 09:00:00']);

    $base = Ledger::query();

    expect(sortedIds($base))->toBe(['small', 'large'])
        ->and($base->orderBy(EntrySort::OutputTokens)->entries(1)->first()?->requestId)->toBe('large')
        ->and(sortedIds($base))->toBe(['small', 'large']);
});
