<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Models;

use Carbon\CarbonImmutable;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroomLedger\Support\Casts\DateOnly;
use Phoenix1331\AiHeadroomLedger\Support\TableNames;

/**
 * Totals for one day and grain (agent, provider, model, count method,
 * currency, primary tag). Money columns are integer micro-units.
 *
 * @internal Read the ledger through the query API, never this model.
 *
 * @property int $id
 * @property CarbonImmutable $date
 * @property class-string|null $agent
 * @property string|null $provider
 * @property string|null $model
 * @property CountMethod|null $count_method
 * @property string|null $currency
 * @property string|null $primary_tag_key
 * @property string|null $primary_tag_value
 * @property int $requests
 * @property int $requests_with_usage
 * @property int $priced_requests
 * @property int $completed
 * @property int $warned
 * @property int $blocked
 * @property int $failed
 * @property int $streamed
 * @property int $estimated_input_tokens
 * @property int $actual_input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property int $estimated_cost
 * @property int $actual_cost
 * @property int $estimated_only_cost
 * @property int $estimated_only_requests
 * @property int $approximate_requests
 * @property int $context_samples
 * @property float $percent_used_sum
 * @property float $percent_used_max
 * @property int $accuracy_samples
 * @property int $accuracy_estimated_tokens
 * @property int $accuracy_actual_tokens
 * @property int $accuracy_absolute_error
 */
class DailyRollup extends LedgerModel
{
    public function getTable(): string
    {
        return TableNames::daily();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => DateOnly::class,
            'count_method' => CountMethod::class,
            'requests' => 'integer',
            'requests_with_usage' => 'integer',
            'priced_requests' => 'integer',
            'completed' => 'integer',
            'warned' => 'integer',
            'blocked' => 'integer',
            'failed' => 'integer',
            'streamed' => 'integer',
            'estimated_input_tokens' => 'integer',
            'actual_input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'estimated_cost' => 'integer',
            'actual_cost' => 'integer',
            'estimated_only_cost' => 'integer',
            'estimated_only_requests' => 'integer',
            'approximate_requests' => 'integer',
            'context_samples' => 'integer',
            'percent_used_sum' => 'float',
            'percent_used_max' => 'float',
            'accuracy_samples' => 'integer',
            'accuracy_estimated_tokens' => 'integer',
            'accuracy_actual_tokens' => 'integer',
            'accuracy_absolute_error' => 'integer',
        ];
    }
}
