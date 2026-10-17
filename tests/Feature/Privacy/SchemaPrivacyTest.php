<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/*
 * The ledger stores numbers, model names, agent class names and allowlisted
 * tags, never prompt or response text. These tests make any new column a
 * deliberate decision: add it to the list below only after confirming it
 * cannot hold text from a prompt, instruction, tool or response.
 */

const LEDGER_COLUMNS = [
    'headroom_ledger_entries' => [
        'request_id', 'invocation_id', 'agent',
        'provider_counted', 'model_counted', 'provider_used', 'model_used', 'count_method',
        'estimated_input_tokens', 'breakdown', 'context_window', 'percent_used',
        'actual_input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens',
        'currency', 'estimated_cost', 'actual_cost', 'cost_approximate',
        'streamed', 'outcome', 'outcome_reason',
        'occurred_on', 'counted_at', 'used_at',
    ],
    'headroom_ledger_tags' => ['id', 'request_id', 'key', 'value'],
    'headroom_ledger_daily' => [
        'id', 'date', 'agent', 'provider', 'model', 'count_method', 'currency', 'primary_tag_key', 'primary_tag_value',
        'requests', 'requests_with_usage', 'priced_requests', 'completed', 'warned', 'blocked', 'failed', 'streamed',
        'estimated_input_tokens', 'actual_input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens',
        'estimated_cost', 'actual_cost', 'estimated_only_cost', 'estimated_only_requests', 'approximate_requests',
        'context_samples', 'percent_used_sum', 'percent_used_max',
        'accuracy_samples', 'accuracy_estimated_tokens', 'accuracy_actual_tokens', 'accuracy_absolute_error',
    ],
];

/** The only JSON column; its cast accepts token counts only. */
const JSON_COLUMNS = ['headroom_ledger_entries.breakdown'];

const TEXT_TYPES = ['text', 'tinytext', 'mediumtext', 'longtext', 'clob', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'bytea', 'binary', 'varbinary'];

it('has exactly the reviewed columns', function (string $table, array $columns): void {
    expect(Schema::getColumnListing($table))->toEqualCanonicalizing($columns);
})->with(fn (): array => array_map(null, array_keys(LEDGER_COLUMNS), array_values(LEDGER_COLUMNS)));

it('has no unbounded text or binary column outside the token breakdown', function (string $table): void {
    $unbounded = collect(Schema::getColumns($table))
        ->reject(fn (array $column): bool => in_array($table.'.'.$column['name'], JSON_COLUMNS, true))
        ->filter(fn (array $column): bool => in_array(strtolower($column['type_name']), TEXT_TYPES, true))
        ->pluck('name')
        ->all();

    expect($unbounded)->toBe([]);
})->with(array_keys(LEDGER_COLUMNS));

it('has no column named after prompt or response content', function (string $table): void {
    expect(Schema::getColumnListing($table))
        ->each->not->toMatch('/(^|_)(prompt|response|message|content|instructions?|body|text|completion)(_|$)/');
})->with(array_keys(LEDGER_COLUMNS));

it('declares no text or binary columns in the migrations', function (): void {
    $migrations = implode("\n", array_map(
        fn (string $file): string => (string) file_get_contents($file),
        glob(__DIR__.'/../../../database/migrations/*.php') ?: [],
    ));

    expect($migrations)
        ->not->toMatch('/->(text|tinyText|mediumText|longText|binary|blob)\(/')
        ->and(substr_count($migrations, '->json('))->toBe(count(JSON_COLUMNS));
});
