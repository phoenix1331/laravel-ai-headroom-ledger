<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Phoenix1331\AiHeadroomLedger\Support\TableNames;

/**
 * An allowlisted context tag on an entry.
 *
 * @internal Read the ledger through the query API, never this model.
 *
 * @property int $id
 * @property string $request_id
 * @property string $key
 * @property string $value
 */
class Tag extends LedgerModel
{
    public function getTable(): string
    {
        return TableNames::tags();
    }

    /**
     * @return BelongsTo<Entry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'request_id', 'request_id');
    }
}
