<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Phoenix1331\AiHeadroomLedger\AiHeadroomLedgerServiceProvider;
use Phoenix1331\AiHeadroomLedger\Database\Factories\LedgerEntryFactory;
use Phoenix1331\AiHeadroomLedger\Facades\Ledger;
use Phoenix1331\AiHeadroomLedger\Models\DailyRollup;
use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Models\RollupDay;
use Phoenix1331\AiHeadroomLedger\Models\Tag;
use Phoenix1331\AiHeadroomLedger\Rollup\DailyRollupBuilder;
use Phoenix1331\AiHeadroomLedger\Support\Retention;

function entriesOn(string $day, int $count = 1, int $cost = 100): void
{
    LedgerEntryFactory::new()->count($count)->at(CarbonImmutable::parse($day.' 10:00:00'))->withTags(['tenant_id' => 42])->create(['actual_cost' => $cost]);
}

function totalSpend(): int
{
    return Ledger::query()->spend()->total->in('USD')?->microUnits ?? 0;
}

/**
 * @return list<string>
 */
function scheduledLedgerCommands(Schedule $schedule): array
{
    return array_values(array_map(
        fn (ScheduledEvent $event): string => preg_replace('/.*(headroom-ledger:\w+).*/', '$1', (string) $event->command).' '.$event->expression,
        array_filter($schedule->events(), fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'headroom-ledger:')),
    ));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-17 12:00:00');
    config([
        'headroom-ledger.tags' => ['allowed' => ['tenant_id'], 'primary_tag' => 'tenant_id'],
        'headroom-ledger.retention_days' => ['entries' => 7, 'rollups' => 30],
    ]);
});

describe('cut-offs', function (): void {
    it('counts back whole days from today', function (): void {
        expect(Retention::entriesCutoff())->toBe('2026-10-10')
            ->and(Retention::rollupsCutoff())->toBe('2026-09-17');
    });

    it('keeps data forever without a positive number of days', function (mixed $days): void {
        config(['headroom-ledger.retention_days' => ['entries' => $days, 'rollups' => $days]]);

        expect(Retention::entriesCutoff())->toBeNull()
            ->and(Retention::rollupsCutoff())->toBeNull();
    })->with([null, 0, -5, 'forever']);

    it('reads numeric strings from the environment', function (): void {
        config(['headroom-ledger.retention_days.entries' => '14']);

        expect(Retention::entriesCutoff())->toBe('2026-10-03');
    });
});

it('prunes entries older than retention and their tags, keeping newer ones', function (): void {
    entriesOn('2026-10-09', 2);
    entriesOn('2026-10-10', 3);

    $this->artisan('headroom-ledger:prune')
        ->expectsOutputToContain('2 deleted')
        ->assertSuccessful();

    expect(Entry::query()->count())->toBe(3)
        ->and(Entry::query()->where('occurred_on', '<', '2026-10-10')->exists())->toBeFalse()
        ->and(Tag::query()->count())->toBe(3);
});

it('removes tags even where the foreign key cascade is not enforced', function (): void {
    entriesOn('2026-10-01', 2);
    DB::statement('PRAGMA foreign_keys = OFF');

    $this->artisan('headroom-ledger:prune')->assertSuccessful();

    DB::statement('PRAGMA foreign_keys = ON');
    expect(Tag::query()->count())->toBe(0);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'Turns SQLite foreign keys off to prove tags do not depend on them.');

it('rolls up days it is about to prune, so their totals survive', function (): void {
    entriesOn('2026-10-01', 2, cost: 150);
    entriesOn('2026-10-12', 1, cost: 50);
    $before = totalSpend();

    $this->artisan('headroom-ledger:prune')
        ->expectsOutputToContain('rolled up first')
        ->assertSuccessful();

    expect(Entry::query()->count())->toBe(1)
        ->and(RollupDay::query()->pluck('date')->map->toDateString()->all())->toBe(['2026-10-01'])
        ->and(totalSpend())->toBe($before)
        ->and(Ledger::query()->forTag('tenant_id', 42)->spend()->requests)->toBe(3);
});

it('does not roll days up again that already were', function (): void {
    entriesOn('2026-10-01');
    (new DailyRollupBuilder)->build('2026-10-01');
    $built = RollupDay::query()->findOrFail('2026-10-01')->built_at;
    CarbonImmutable::setTestNow('2026-10-17 13:00:00');

    $this->artisan('headroom-ledger:prune')->assertSuccessful();

    expect(RollupDay::query()->findOrFail('2026-10-01')->built_at)->toEqual($built);
});

it('prunes without rolling up when rollups are off', function (): void {
    config(['headroom-ledger.rollup.enabled' => false]);
    entriesOn('2026-10-01');

    $this->artisan('headroom-ledger:prune')->assertSuccessful();

    expect(Entry::query()->count())->toBe(0)
        ->and(RollupDay::query()->count())->toBe(0);
});

it('prunes rollups and their rolled-up days older than rollup retention', function (): void {
    entriesOn('2026-09-10');
    entriesOn('2026-09-20');
    (new DailyRollupBuilder)->build('2026-09-10');
    (new DailyRollupBuilder)->build('2026-09-20');

    $this->artisan('headroom-ledger:prune')->assertSuccessful();

    expect(DailyRollup::query()->pluck('date')->map->toDateString()->unique()->values()->all())->toBe(['2026-09-20'])
        ->and(RollupDay::query()->pluck('date')->map->toDateString()->all())->toBe(['2026-09-20']);
});

it('keeps everything when retention is off', function (): void {
    config(['headroom-ledger.retention_days' => ['entries' => null, 'rollups' => null]]);
    entriesOn('2020-01-01');
    (new DailyRollupBuilder)->build('2020-01-01');

    $this->artisan('headroom-ledger:prune')
        ->expectsOutputToContain('kept forever')
        ->assertSuccessful();

    expect(Entry::query()->count())->toBe(1)
        ->and(DailyRollup::query()->count())->toBe(1);
});

it('only reports what it would do with --pretend', function (): void {
    entriesOn('2026-10-01', 2);
    entriesOn('2026-09-01');
    (new DailyRollupBuilder)->build('2026-09-01');

    $this->artisan('headroom-ledger:prune', ['--pretend' => true])
        ->expectsOutputToContain('3 would be deleted')
        ->expectsOutputToContain('to roll up first')
        ->assertSuccessful();

    expect(Entry::query()->count())->toBe(3)
        ->and(DailyRollup::query()->count())->toBe(1)
        ->and(RollupDay::query()->count())->toBe(1);
});

it('works through Laravel model:prune too', function (): void {
    entriesOn('2026-10-01');

    $this->artisan('model:prune', ['--model' => [Entry::class]])->assertSuccessful();

    expect(Entry::query()->count())->toBe(0)
        ->and(Tag::query()->count())->toBe(0);
});

describe('rebuilding pruned days', function (): void {
    it('refuses to rebuild a rolled-up day whose entries are past retention', function (): void {
        entriesOn('2026-10-01', 2);
        $this->artisan('headroom-ledger:prune')->assertSuccessful();

        expect((new DailyRollupBuilder)->build('2026-10-01'))->toBeNull()
            ->and((int) DailyRollup::query()->where('date', '2026-10-01')->sum('requests'))->toBe(2);
    });

    it('reports the day as kept in the rollup command', function (): void {
        entriesOn('2026-10-01');
        $this->artisan('headroom-ledger:prune')->assertSuccessful();

        $this->artisan('headroom-ledger:rollup', ['--date' => '2026-10-01'])
            ->expectsOutputToContain('kept: entries already pruned')
            ->assertSuccessful();
    });

    it('still builds an old day that was never rolled up', function (): void {
        entriesOn('2026-10-01');

        expect((new DailyRollupBuilder)->build('2026-10-01'))->toBe(1);
    });
});

describe('schedule', function (): void {
    it('schedules pruning after the rollup by default', function (): void {
        expect(scheduledLedgerCommands($this->app->make(Schedule::class)))
            ->toBe(['headroom-ledger:rollup 15 0 * * *', 'headroom-ledger:prune 30 0 * * *']);
    });

    it('schedules pruning on its own cron, on one server, even with rollups off', function (): void {
        config(['headroom-ledger.prune.schedule' => '0 3 * * 0', 'headroom-ledger.rollup.enabled' => false]);
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);

        (new AiHeadroomLedgerServiceProvider($this->app))->boot();
        $this->app->make(Schedule::class);

        $prune = array_values(array_filter($schedule->events(), fn (ScheduledEvent $e): bool => str_contains((string) $e->command, 'prune')));

        expect(scheduledLedgerCommands($schedule))->toBe(['headroom-ledger:prune 0 3 * * 0'])
            ->and($prune[0]->onOneServer)->toBeTrue()
            ->and($prune[0]->withoutOverlapping)->toBeTrue();
    });

    it('leaves pruning to the app when its schedule is false', function (): void {
        config(['headroom-ledger.prune.schedule' => false]);
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);

        (new AiHeadroomLedgerServiceProvider($this->app))->boot();
        $this->app->make(Schedule::class);

        expect(scheduledLedgerCommands($schedule))->toBe(['headroom-ledger:rollup 15 0 * * *']);
    });

    it('refuses an invalid prune schedule', function (): void {
        config(['headroom-ledger.prune.schedule' => 'nightly']);
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);

        (new AiHeadroomLedgerServiceProvider($this->app))->boot();
        $this->app->make(Schedule::class);
    })->throws(InvalidArgumentException::class, 'headroom-ledger.prune.schedule must be a cron expression');
});

it('selects nothing to prune through model:prune when retention is off', function (): void {
    config(['headroom-ledger.retention_days' => ['entries' => null, 'rollups' => null]]);
    entriesOn('2020-01-01');
    (new DailyRollupBuilder)->build('2020-01-01');

    expect((new Entry)->prunable()->count())->toBe(0)
        ->and((new DailyRollup)->prunable()->count())->toBe(0)
        ->and((new RollupDay)->prunable()->count())->toBe(0);

    $this->artisan('model:prune', ['--model' => [Entry::class, DailyRollup::class, RollupDay::class]])->assertSuccessful();

    expect(Entry::query()->count())->toBe(1)
        ->and(RollupDay::query()->count())->toBe(1);
});
