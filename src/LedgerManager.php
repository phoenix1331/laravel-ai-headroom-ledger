<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Phoenix1331\AiHeadroomLedger\Data\LedgerEntries;
use Phoenix1331\AiHeadroomLedger\Data\LedgerEntry;
use Phoenix1331\AiHeadroomLedger\Data\RunSummary;
use Phoenix1331\AiHeadroomLedger\Models\DailyRollup;
use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Models\Tag;
use Phoenix1331\AiHeadroomLedger\Query\EntryMapper;
use Phoenix1331\AiHeadroomLedger\Query\LedgerQuery;
use Phoenix1331\AiHeadroomLedger\Support\TagValue;

/**
 * The ledger's public entry point, behind the Ledger facade.
 */
final class LedgerManager
{
    /**
     * A new, unfiltered query over the ledger.
     */
    public function query(): LedgerQuery
    {
        return new LedgerQuery;
    }

    /**
     * One request by its Headroom request ID, or null if it is not in the ledger.
     */
    public function find(string $requestId): ?LedgerEntry
    {
        $row = Entry::query()->with('tags')->find($requestId);

        return $row === null ? null : EntryMapper::toEntry($row);
    }

    /**
     * Every recorded step of one agent run, in order, with totals; null if none were recorded.
     */
    public function run(string $invocationId): ?RunSummary
    {
        $rows = Entry::query()
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

    /**
     * Forget a data subject: delete every tag with this key and value, give
     * the runs those entries belong to fresh invocation IDs (so steps still
     * group together but no longer match the app's own logs), and clear the
     * value from daily rollups that used it as the primary tag, so they read
     * as untagged, like the entries.
     *
     * Other tags on the same entries are kept; call this once per personal
     * key. Entries recorded by listeners still on the queue are not covered,
     * so run it once the ledger queue has drained.
     *
     * @return int The number of entries that carried the tag.
     */
    public function forgetTag(string $key, string|int|float|bool $value): int
    {
        $value = TagValue::from($value);

        return (new Entry)->getConnection()->transaction(function () use ($key, $value): int {
            $forgotten = 0;

            Tag::query()
                ->where('key', $key)
                ->where('value', $value)
                ->chunkById(500, function (Collection $tags) use (&$forgotten): void {
                    /** @var Collection<int, Tag> $tags */
                    $forgotten += $tags->count();

                    $this->renameRuns($tags->map(fn (Tag $tag): string => $tag->request_id)->values()->all());
                });

            Tag::query()->where('key', $key)->where('value', $value)->delete();

            DailyRollup::query()
                ->where('primary_tag_key', $key)
                ->where('primary_tag_value', $value)
                ->update(['primary_tag_value' => null]);

            return $forgotten;
        });
    }

    /**
     * @param  array<int, string>  $requestIds
     */
    private function renameRuns(array $requestIds): void
    {
        $runs = Entry::query()
            ->whereIn('request_id', $requestIds)
            ->whereNotNull('invocation_id')
            ->distinct()
            ->pluck('invocation_id');

        foreach ($runs as $run) {
            Entry::query()->where('invocation_id', $run)->update(['invocation_id' => (string) Str::uuid7()]);
        }
    }
}
