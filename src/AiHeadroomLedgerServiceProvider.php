<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Phoenix1331\AiHeadroom\Events\HeadroomLow;
use Phoenix1331\AiHeadroom\Events\RequestBlocked;
use Phoenix1331\AiHeadroom\Events\RequestFailed;
use Phoenix1331\AiHeadroom\Events\TokensCounted;
use Phoenix1331\AiHeadroom\Events\TokensUsed;
use Phoenix1331\AiHeadroomLedger\Console\RollupCommand;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordHeadroomLow;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordRequestBlocked;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordRequestFailed;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordTokensCounted;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordTokensUsed;

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

        $this->commands([RollupCommand::class]);

        if (! $this->app->make('config')->boolean('headroom-ledger.enabled', true)) {
            return;
        }

        $this->registerListeners($this->app->make(Dispatcher::class));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $this->scheduleRollups($schedule);
        });
    }

    /**
     * Schedule the rollup on the configured cron expression, unless rollups
     * are off or the app schedules the command itself (schedule: false). One
     * server only: each day is rebuilt as delete-then-insert, so two
     * concurrent runs could double its rows.
     */
    private function scheduleRollups(Schedule $schedule): void
    {
        $config = $this->app->make('config');
        $expression = $config->get('headroom-ledger.rollup.schedule', '15 0 * * *');

        if (! $config->boolean('headroom-ledger.rollup.enabled', true) || $expression === false || $expression === null || $expression === '') {
            return;
        }

        if (! is_string($expression) || ! CronExpression::isValidExpression($expression)) {
            throw new InvalidArgumentException(
                'headroom-ledger.rollup.schedule must be a cron expression such as "15 0 * * *", or false to schedule headroom-ledger:rollup yourself.'
            );
        }

        $schedule->command(RollupCommand::class)
            ->cron($expression)
            ->withoutOverlapping()
            ->onOneServer();
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
