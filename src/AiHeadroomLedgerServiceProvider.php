<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Phoenix1331\AiHeadroom\Events\TokensCounted;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordTokensCounted;

class AiHeadroomLedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/headroom-ledger.php', 'headroom-ledger');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/headroom-ledger.php' => config_path('headroom-ledger.php'),
        ], 'headroom-ledger-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'headroom-ledger-migrations');

        if ($this->app->make('config')->boolean('headroom-ledger.enabled', true)) {
            $this->registerListeners($this->app->make(Dispatcher::class));
        }
    }

    private function registerListeners(Dispatcher $events): void
    {
        $events->listen(TokensCounted::class, RecordTokensCounted::class);
    }
}
