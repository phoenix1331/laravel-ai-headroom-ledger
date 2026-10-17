<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Phoenix1331\AiHeadroomLedger\Support\Casts\DateOnly;
use Phoenix1331\AiHeadroomLedger\Support\Retention;
use Phoenix1331\AiHeadroomLedger\Support\TableNames;

/**
 * A day the daily rollups cover, even if it had no entries, and the primary
 * tag they were built with. Locked while the day is rebuilt.
 *
 * @internal Read the ledger through the query API, never this model.
 *
 * @property CarbonImmutable $date
 * @property string|null $primary_tag_key
 * @property int $rows
 * @property CarbonImmutable|null $built_at
 */
class RollupDay extends LedgerModel
{
    use MassPrunable;

    protected $primaryKey = 'date';

    protected $keyType = 'string';

    public $incrementing = false;

    public function getTable(): string
    {
        return TableNames::rollupDays();
    }

    /**
     * Rolled-up days before the rollup retention cut-off, pruned with their rollups.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $cutoff = Retention::rollupsCutoff();

        return $cutoff === null
            ? static::query()->whereRaw('1 = 0')
            : static::query()->where('date', '<', $cutoff);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => DateOnly::class,
            'rows' => 'integer',
            'built_at' => 'immutable_datetime',
        ];
    }
}
