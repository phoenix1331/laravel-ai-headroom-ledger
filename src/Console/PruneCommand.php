<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Phoenix1331\AiHeadroomLedger\Models\DailyRollup;
use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Models\RollupDay;
use Phoenix1331\AiHeadroomLedger\Models\Tag;
use Phoenix1331\AiHeadroomLedger\Rollup\DailyRollupBuilder;
use Phoenix1331\AiHeadroomLedger\Support\Retention;
use Phoenix1331\AiHeadroomLedger\Support\TableNames;

final class PruneCommand extends Command
{
    protected $signature = 'headroom-ledger:prune
        {--pretend : Show what would be pruned without deleting anything}';

    protected $description = 'Delete Headroom Ledger entries and rollups older than their retention, rolling days up before their entries go';

    public function handle(DailyRollupBuilder $builder): int
    {
        $pretend = (bool) $this->option('pretend');
        $entriesCutoff = Retention::entriesCutoff();
        $rollupsCutoff = Retention::rollupsCutoff();

        if ($entriesCutoff === null) {
            $this->components->twoColumnDetail('Entries', 'kept forever');
        } else {
            $this->pruneEntries($builder, $entriesCutoff, $pretend);
        }

        if ($rollupsCutoff === null) {
            $this->components->twoColumnDetail('Rollups', 'kept forever');
        } else {
            $this->pruneRollups($rollupsCutoff, $pretend);
        }

        return self::SUCCESS;
    }

    private function pruneEntries(DailyRollupBuilder $builder, string $cutoff, bool $pretend): void
    {
        $unrolled = $this->unrolledDaysBefore($cutoff);
        $entries = (new Entry)->prunable()->count();

        if ($pretend) {
            $this->components->twoColumnDetail("Days before {$cutoff} to roll up first", (string) count($unrolled));
            $this->components->twoColumnDetail("Entries before {$cutoff}", "{$entries} would be deleted");

            return;
        }

        foreach ($unrolled as $day) {
            $builder->build($day);
        }

        // Explicit, so tags go even where the foreign key cascade is not enforced.
        Tag::query()->whereIn('request_id', (new Entry)->prunable()->select('request_id'))->delete();
        $deleted = (new Entry)->pruneAll();

        $this->components->twoColumnDetail("Days before {$cutoff} rolled up first", (string) count($unrolled));
        $this->components->twoColumnDetail("Entries before {$cutoff}", "{$deleted} deleted");
    }

    private function pruneRollups(string $cutoff, bool $pretend): void
    {
        if ($pretend) {
            $this->components->twoColumnDetail("Rollup rows before {$cutoff}", (new DailyRollup)->prunable()->count().' would be deleted');

            return;
        }

        $deleted = (new DailyRollup)->pruneAll();
        (new RollupDay)->pruneAll();

        $this->components->twoColumnDetail("Rollup rows before {$cutoff}", "{$deleted} deleted");
    }

    /**
     * Days with entries about to be pruned that have no rollup yet. None when
     * rollups are off: then nothing reads them.
     *
     * @return list<string>
     */
    private function unrolledDaysBefore(string $cutoff): array
    {
        if (! config()->boolean('headroom-ledger.rollup.enabled', true)) {
            return [];
        }

        $entries = TableNames::entries();
        $days = TableNames::rollupDays();

        return array_values(Entry::query()->toBase()
            ->where('occurred_on', '<', $cutoff)
            ->whereNotExists(fn (QueryBuilder $query) => $query->from($days)->whereColumn($days.'.date', $entries.'.occurred_on'))
            ->distinct()
            ->orderBy('occurred_on')
            ->pluck('occurred_on')
            ->map(fn (mixed $day): string => substr(is_scalar($day) ? (string) $day : '', 0, 10))
            ->all());
    }
}
