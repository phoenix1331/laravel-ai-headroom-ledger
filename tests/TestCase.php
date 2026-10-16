<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Tests;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Phoenix1331\AiHeadroom\AiHeadroomServiceProvider;
use Phoenix1331\AiHeadroomLedger\AiHeadroomLedgerServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // The ledger never calls a provider; any HTTP request in a test is a bug.
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            AiHeadroomServiceProvider::class,
            AiHeadroomLedgerServiceProvider::class,
        ];
    }
}
