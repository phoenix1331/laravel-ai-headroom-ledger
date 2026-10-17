<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroomLedger\Database\Factories\LedgerEntryFactory;
use Phoenix1331\AiHeadroomLedger\Enums\Outcome;
use Phoenix1331\AiHeadroomLedger\Support\Casts\DateOnly;
use Phoenix1331\AiHeadroomLedger\Support\Casts\ReasonCode;
use Phoenix1331\AiHeadroomLedger\Support\Casts\TokenBreakdown;
use Phoenix1331\AiHeadroomLedger\Support\Retention;
use Phoenix1331\AiHeadroomLedger\Support\TableNames;

/**
 * One AI provider call. Money columns are integer micro-units. No attribute
 * can hold prompt or response text: see the TokenBreakdown and ReasonCode casts.
 *
 * @internal Read the ledger through the query API, never this model.
 *
 * @property string $request_id
 * @property string|null $invocation_id
 * @property class-string|null $agent
 * @property string|null $provider_counted
 * @property string|null $model_counted
 * @property string|null $provider_used
 * @property string|null $model_used
 * @property CountMethod|null $count_method
 * @property int|null $estimated_input_tokens
 * @property array<string, int>|null $breakdown
 * @property int|null $context_window
 * @property float|null $percent_used
 * @property int|null $actual_input_tokens
 * @property int|null $output_tokens
 * @property int|null $cache_read_tokens
 * @property int|null $cache_write_tokens
 * @property string|null $currency
 * @property int|null $estimated_cost
 * @property int|null $actual_cost
 * @property bool $cost_approximate
 * @property bool $streamed
 * @property Outcome $outcome
 * @property string|null $outcome_reason
 * @property CarbonImmutable $occurred_on
 * @property CarbonImmutable|null $counted_at
 * @property CarbonImmutable|null $used_at
 */
class Entry extends LedgerModel
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory;

    use MassPrunable;

    protected $primaryKey = 'request_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'outcome' => 'counted',
        'cost_approximate' => false,
        'streamed' => false,
    ];

    public function getTable(): string
    {
        return TableNames::entries();
    }

    /**
     * Entries from days before the entry retention cut-off. Use
     * headroom-ledger:prune, which rolls those days up first and removes
     * their tags; model:prune relies on the foreign key cascade for tags.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $cutoff = Retention::entriesCutoff();

        return $cutoff === null
            ? static::query()->whereRaw('1 = 0')
            : static::query()->where('occurred_on', '<', $cutoff);
    }

    protected static function newFactory(): LedgerEntryFactory
    {
        return LedgerEntryFactory::new();
    }

    /**
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class, 'request_id', 'request_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'count_method' => CountMethod::class,
            'estimated_input_tokens' => 'integer',
            'breakdown' => TokenBreakdown::class,
            'context_window' => 'integer',
            'percent_used' => 'float',
            'actual_input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'estimated_cost' => 'integer',
            'actual_cost' => 'integer',
            'cost_approximate' => 'boolean',
            'streamed' => 'boolean',
            'outcome' => Outcome::class,
            'outcome_reason' => ReasonCode::class,
            'occurred_on' => DateOnly::class,
            'counted_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }
}
