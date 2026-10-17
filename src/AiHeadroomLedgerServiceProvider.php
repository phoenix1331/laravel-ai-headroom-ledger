<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Phoenix1331\AiHeadroom\Events\HeadroomLow;
use Phoenix1331\AiHeadroom\Events\RequestBlocked;
use Phoenix1331\AiHeadroom\Events\RequestFailed;
use Phoenix1331\AiHeadroom\Events\TokensCounted;
use Phoenix1331\AiHeadroom\Events\TokensUsed;
use Phoenix1331\AiHeadroomLedger\Console\PruneCommand;
use Phoenix1331\AiHeadroomLedger\Console\ReportCommand;
use Phoenix1331\AiHeadroomLedger\Console\RollupCommand;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordHeadroomLow;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordRequestBlocked;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordRequestFailed;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordTokensCounted;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordTokensUsed;
use Phoenix1331\AiHeadroomLedger\Support\CronSchedule;

class AiHeadroomLedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/headroom-ledger.php', 'headroom-ledger');

        $this->app->singleton(LedgerManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/headroom-ledger.php' => config_path('headroom-ledger.php'),
        ], 'headroom-ledger-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'headroom-ledger-migrations');

        $this->commands([RollupCommand::class, PruneCommand::class, ReportCommand::class]);

        if (! $this->app->make('config')->boolean('headroom-ledger.enabled', true)) {
            return;
        }

        $this->registerListeners($this->app->make(Dispatcher::class));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $this->scheduleCommands($schedule);
        });
    }

    /**
     * Schedule the rollup and prune commands on their configured cron
     * expressions, unless the app schedules them itself (false). One server
     * only: each day is rebuilt as delete-then-insert, and pruning rolls days
     * up first.
     */
    private function scheduleCommands(Schedule $schedule): void
    {
        $rollup = $this->app->make('config')->boolean('headroom-ledger.rollup.enabled', true)
            ? CronSchedule::from('headroom-ledger.rollup.schedule', 'headroom-ledger:rollup')
            : null;
        $prune = CronSchedule::from('headroom-ledger.prune.schedule', 'headroom-ledger:prune');

        if ($rollup !== null) {
            $schedule->command(RollupCommand::class)->cron($rollup)->withoutOverlapping()->onOneServer();
        }

        if ($prune !== null) {
            $schedule->command(PruneCommand::class)->cron($prune)->withoutOverlapping()->onOneServer();
        }
    }

    private function registerListeners(Dispatcher $events): void
    {
        $events->listen(TokensCounted::class, RecordTokensCounted::class);
        $events->listen(TokensUsed::class, RecordTokensUsed::class);
        $events->listen(RequestBlocked::class, RecordRequestBlocked::class);
        $events->listen(HeadroomLow::class, RecordHeadroomLow::class);
        $events->listen(RequestFailed::class, RecordRequestFailed::class);
    }
}
