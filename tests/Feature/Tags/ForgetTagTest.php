<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Phoenix1331\AiHeadroomLedger\Database\Factories\LedgerEntryFactory;
use Phoenix1331\AiHeadroomLedger\Facades\Ledger;
use Phoenix1331\AiHeadroomLedger\LedgerManager;
use Phoenix1331\AiHeadroomLedger\Models\DailyRollup;
use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Models\Tag;

function rollup(array $attributes): DailyRollup
{
    return DailyRollup::query()->create($attributes + ['date' => '2026-10-16', 'requests' => 1]);
}

it('resolves the facade to the manager singleton', function (): void {
    expect(Ledger::getFacadeRoot())->toBeInstanceOf(LedgerManager::class)
        ->and(app(LedgerManager::class))->toBe(app(LedgerManager::class));
});

it('deletes the tag for that subject only', function (): void {
    LedgerEntryFactory::new()->count(2)->withTags(['user_id' => 123, 'tenant_id' => 9])->create();
    LedgerEntryFactory::new()->withTags(['user_id' => 456, 'tenant_id' => 9])->create();

    Ledger::forgetTag('user_id', 123);

    expect(Tag::query()->where('key', 'user_id')->pluck('value')->all())->toBe(['456'])
        ->and(Tag::query()->where('key', 'tenant_id')->count())->toBe(3);
});

it('returns how many entries carried the tag', function (): void {
    LedgerEntryFactory::new()->count(3)->withTags(['user_id' => 123])->create();

    expect(Ledger::forgetTag('user_id', 123))->toBe(3)
        ->and(Ledger::forgetTag('user_id', 123))->toBe(0);
});

it('keeps the entries and their figures', function (): void {
    $entry = LedgerEntryFactory::new()->withTags(['user_id' => 123])->create();

    Ledger::forgetTag('user_id', 123);

    expect(Entry::query()->findOrFail($entry->request_id))
        ->actual_cost->toBe($entry->actual_cost)
        ->estimated_input_tokens->toBe($entry->estimated_input_tokens)
        ->model_counted->toBe($entry->model_counted);
});

it('gives affected runs fresh invocation IDs that still group their steps', function (): void {
    LedgerEntryFactory::new()->count(3)->inRun('run-of-123')->withTags(['user_id' => 123])->create();
    // A step of the same run recorded without the tag, e.g. before tagging was set up.
    LedgerEntryFactory::new()->inRun('run-of-123')->create();
    LedgerEntryFactory::new()->count(2)->inRun('run-of-456')->withTags(['user_id' => 456])->create();

    Ledger::forgetTag('user_id', 123);

    $renamed = Entry::query()->where('invocation_id', '!=', 'run-of-456')->pluck('invocation_id')->unique();

    expect(Entry::query()->where('invocation_id', 'run-of-123')->exists())->toBeFalse()
        ->and($renamed)->toHaveCount(1)
        ->and(Entry::query()->where('invocation_id', $renamed->first())->count())->toBe(4)
        ->and(Entry::query()->where('invocation_id', 'run-of-456')->count())->toBe(2);
});

it('gives separate runs separate new IDs', function (): void {
    LedgerEntryFactory::new()->count(2)->inRun('run-a')->withTags(['user_id' => 123])->create();
    LedgerEntryFactory::new()->count(2)->inRun('run-b')->withTags(['user_id' => 123])->create();

    Ledger::forgetTag('user_id', 123);

    expect(Entry::query()->distinct()->pluck('invocation_id')->filter()->unique())->toHaveCount(2)
        ->and(Entry::query()->whereIn('invocation_id', ['run-a', 'run-b'])->exists())->toBeFalse();
});

it('handles entries without an invocation', function (): void {
    LedgerEntryFactory::new()->withTags(['user_id' => 123])->create(['invocation_id' => null]);

    expect(Ledger::forgetTag('user_id', 123))->toBe(1)
        ->and(Entry::query()->firstOrFail()->invocation_id)->toBeNull();
});

it('covers more entries than one chunk', function (): void {
    LedgerEntryFactory::new()->count(501)->withTags(['user_id' => 123])->create();

    expect(Ledger::forgetTag('user_id', 123))->toBe(501)
        ->and(Tag::query()->count())->toBe(0);
});

it('clears the subject from daily rollups that used it as the primary tag', function (): void {
    rollup(['primary_tag_key' => 'user_id', 'primary_tag_value' => '123', 'actual_cost' => 900]);
    rollup(['primary_tag_key' => 'user_id', 'primary_tag_value' => '456']);
    rollup(['primary_tag_key' => 'tenant_id', 'primary_tag_value' => '123']);

    Ledger::forgetTag('user_id', 123);

    expect(DailyRollup::query()->where('actual_cost', 900)->firstOrFail())
        ->primary_tag_key->toBe('user_id')
        ->primary_tag_value->toBeNull()
        ->and(DailyRollup::query()->where('primary_tag_value', '456')->exists())->toBeTrue()
        ->and(DailyRollup::query()->where('primary_tag_key', 'tenant_id')->exists())->toBeTrue();
});

it('matches values the way they were recorded', function (string|int|float|bool $recorded, string|int|float|bool $forgotten): void {
    LedgerEntryFactory::new()->withTags(['subject' => $recorded])->create();

    expect(Ledger::forgetTag('subject', $forgotten))->toBe(1);
})->with([
    'int as string' => [123, '123'],
    'string as int' => ['123', 123],
    'bool' => [true, true],
    'float' => [1.5, 1.5],
]);

it('works on a separate connection with a custom prefix', function (): void {
    config([
        'database.connections.ledger' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        'headroom-ledger.connection' => 'ledger',
        'headroom-ledger.table_prefix' => 'ai_',
    ]);
    $files = glob(__DIR__.'/../../../database/migrations/*.php') ?: [];
    $migration = require $files[0];
    DB::usingConnection('ledger', fn () => $migration->up());

    LedgerEntryFactory::new()->withTags(['user_id' => 123])->create();

    expect(Ledger::forgetTag('user_id', 123))->toBe(1)
        ->and(Tag::query()->count())->toBe(0);
});
