<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Models;

use Illuminate\Database\Eloquent\Model;
use Phoenix1331\AiHeadroomLedger\Support\TableNames;

/**
 * Resolves the configured connection for every ledger model.
 *
 * @internal Read the ledger through the query API, never these models.
 */
abstract class LedgerModel extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? TableNames::connection();
    }
}
