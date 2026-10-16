<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger;

use Illuminate\Support\ServiceProvider;

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
    }
}
