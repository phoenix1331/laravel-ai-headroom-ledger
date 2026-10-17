<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroomLedger\AiHeadroomLedgerServiceProvider;
use Phoenix1331\AiHeadroomLedger\Database\Factories\LedgerEntryFactory;
use Phoenix1331\AiHeadroomLedger\Models\DailyRollup;

function entryOn(string $day, int $count = 1): void
{
    LedgerEntryFactory::new()->count($count)->at(CarbonImmutable::parse($day.' 10:00:00'))->create();
}

/**
 * @return list<string>
 */
function rolledDays(): array
{
    return DailyRollup::query()->distinct()->orderBy('date')->pluck('date')
        ->map(fn (CarbonImmutable $date): string => $date->toDateString())->all();
}

/**
 * @return list<ScheduledEvent>
 */
function ledgerSchedule(Schedule $schedule): array
{
    return array_values(array_filter($schedule->events(), fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'headroom-ledger:rollup')));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-17 12:00:00');
});

it('rolls up yesterday when there is nothing older', function (): void {
    entryOn('2026-10-16', 2);
    entryOn('2026-10-17');

    $this->artisan('headroom-ledger:rollup')
        ->expectsOutputToContain('2026-10-16')
        ->assertSuccessful();

    expect(rolledDays())->toBe(['2026-10-16'])
        ->and((int) DailyRollup::query()->sum('requests'))->toBe(2);
});

it('rolls up yesterday on an empty ledger', function (): void {
    $this->artisan('headroom-ledger:rollup')->expectsOutputToContain('2026-10-16')->assertSuccessful();
});

it('catches up every closed day since the last rollup, rebuilding that day for late entries', function (): void {
    entryOn('2026-10-12');
    $this->artisan('headroom-ledger:rollup', ['--date' => '2026-10-12'])->assertSuccessful();
    entryOn('2026-10-12');
    entryOn('2026-10-14');

    $this->artisan('headroom-ledger:rollup')
        ->expectsOutputToContain('2026-10-12')
        ->expectsOutputToContain('2026-10-16')
        ->assertSuccessful();

    expect(rolledDays())->toBe(['2026-10-12', '2026-10-14'])
        ->and((int) DailyRollup::query()->where('date', '2026-10-12')->sum('requests'))->toBe(2);
});

it('backfills from the oldest entry on a first run', function (): void {
    entryOn('2026-10-10');
    entryOn('2026-10-15');

    $this->artisan('headroom-ledger:rollup')->assertSuccessful();

    expect(rolledDays())->toBe(['2026-10-10', '2026-10-15']);
});

it('catches up no more than 31 days', function (): void {
    entryOn('2026-01-01');
    entryOn('2026-09-16');
    entryOn('2026-09-15');

    $this->artisan('headroom-ledger:rollup')
        ->doesntExpectOutputToContain('2026-09-15')
        ->assertSuccessful();

    expect(rolledDays())->toBe(['2026-09-16']);
});

it('still rebuilds yesterday when today was rolled up by hand', function (): void {
    entryOn('2026-10-16');
    entryOn('2026-10-17');
    $this->artisan('headroom-ledger:rollup', ['--date' => '2026-10-17'])->assertSuccessful();

    $this->artisan('headroom-ledger:rollup')->assertSuccessful();

    expect(rolledDays())->toBe(['2026-10-16', '2026-10-17']);
});

it('runs safely again and again, as an hourly schedule would', function (): void {
    entryOn('2026-10-16', 3);

    $this->artisan('headroom-ledger:rollup')->assertSuccessful();
    $this->artisan('headroom-ledger:rollup')->assertSuccessful();

    expect((int) DailyRollup::query()->sum('requests'))->toBe(3);
});

it('rolls up one given day, including today', function (): void {
    entryOn('2026-10-17', 3);

    $this->artisan('headroom-ledger:rollup', ['--date' => '2026-10-17'])->assertSuccessful();

    expect(rolledDays())->toBe(['2026-10-17']);
});

it('backfills a range of days', function (): void {
    entryOn('2026-10-12');
    entryOn('2026-10-14');

    $this->artisan('headroom-ledger:rollup', ['--from' => '2026-10-12', '--to' => '2026-10-14'])
        ->expectsOutputToContain('2026-10-13')
        ->assertSuccessful();

    expect(rolledDays())->toBe(['2026-10-12', '2026-10-14']);
});

it('backfills up to yesterday when no end is given', function (): void {
    entryOn('2026-10-15');
    entryOn('2026-10-16');
    entryOn('2026-10-17');

    $this->artisan('headroom-ledger:rollup', ['--from' => '2026-10-15'])->assertSuccessful();

    expect(rolledDays())->toBe(['2026-10-15', '2026-10-16']);
});

it('reports the rows written for each day', function (): void {
    LedgerEntryFactory::new()->at(CarbonImmutable::parse('2026-10-16 10:00'))->forModel('gpt-5')->forAgent('App\\Ai\\Agents\\SupportAgent')
        ->countMethod(CountMethod::Native)->create();

    $this->artisan('headroom-ledger:rollup')->expectsOutputToContain('1 row')->assertSuccessful();
    $this->artisan('headroom-ledger:rollup', ['--date' => '2026-10-10'])->expectsOutputToContain('0 rows')->assertSuccessful();
});

it('rejects dates that are not real Y-m-d days', function (array $options): void {
    $this->artisan('headroom-ledger:rollup', $options)
        ->expectsOutputToContain('Y-m-d')
        ->assertFailed();

    expect(DailyRollup::query()->count())->toBe(0);
})->with([
    'words' => [['--date' => 'yesterday']],
    'day first' => [['--date' => '16-10-2026']],
    'impossible day' => [['--date' => '2026-02-30']],
    'bad range start' => [['--from' => '2026-13-01']],
    'bad range end' => [['--from' => '2026-10-01', '--to' => 'soon']],
]);

it('rejects a range that ends before it starts', function (): void {
    $this->artisan('headroom-ledger:rollup', ['--from' => '2026-10-16', '--to' => '2026-10-10'])
        ->expectsOutputToContain('starts (2026-10-16) after it ends (2026-10-10)')
        ->assertFailed();
});

it('schedules the rollup on the configured cron expression, on one server', function (): void {
    config(['headroom-ledger.rollup.schedule' => '30 * * * *']);
    $schedule = new Schedule;
    $this->app->instance(Schedule::class, $schedule);

    (new AiHeadroomLedgerServiceProvider($this->app))->boot();
    $this->app->make(Schedule::class);

    $events = ledgerSchedule($schedule);

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('30 * * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->onOneServer)->toBeTrue();
});

it('is scheduled by default', function (): void {
    expect(ledgerSchedule($this->app->make(Schedule::class)))->toHaveCount(1)
        ->and(ledgerSchedule($this->app->make(Schedule::class))[0]->expression)->toBe('15 0 * * *');
});

it('is not scheduled when rollups or the ledger are disabled, or the app schedules it itself', function (string $key, mixed $value): void {
    config([$key => $value]);
    $schedule = new Schedule;
    $this->app->instance(Schedule::class, $schedule);

    (new AiHeadroomLedgerServiceProvider($this->app))->boot();
    $this->app->make(Schedule::class);

    expect(ledgerSchedule($schedule))->toBe([]);
})->with([
    'rollups disabled' => ['headroom-ledger.rollup.enabled', false],
    'ledger disabled' => ['headroom-ledger.enabled', false],
    'schedule false' => ['headroom-ledger.rollup.schedule', false],
    'schedule null' => ['headroom-ledger.rollup.schedule', null],
    'schedule empty' => ['headroom-ledger.rollup.schedule', ''],
]);

it('refuses an invalid schedule', function (mixed $value): void {
    config(['headroom-ledger.rollup.schedule' => $value]);
    $schedule = new Schedule;
    $this->app->instance(Schedule::class, $schedule);

    (new AiHeadroomLedgerServiceProvider($this->app))->boot();
    $this->app->make(Schedule::class);
})->with(['daily', '61 * * * *', 'true'])->throws(InvalidArgumentException::class, 'cron expression');

it('refuses a schedule that is not a string', function (): void {
    config(['headroom-ledger.rollup.schedule' => true]);
    $schedule = new Schedule;
    $this->app->instance(Schedule::class, $schedule);

    (new AiHeadroomLedgerServiceProvider($this->app))->boot();
    $this->app->make(Schedule::class);
})->throws(InvalidArgumentException::class, 'or false to schedule');
