<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroomLedger\Database\Factories\LedgerEntryFactory;

/**
 * A week of demo traffic for three agents, for trying the package and the
 * report command locally. Figures are random, so each run differs a little.
 *
 *     vendor/bin/testbench migrate:fresh
 *     vendor/bin/testbench db:seed --class="Workbench\Database\Seeders\DemoLedgerSeeder"
 *     vendor/bin/testbench headroom-ledger:report --by=agent
 */
final class DemoLedgerSeeder extends Seeder
{
    /** @var array<class-string|string, array{0: int, 1: string, 2: CountMethod}> */
    private const array AGENTS = [
        'App\\Ai\\Agents\\SupportAgent' => [140, 'claude-sonnet-5-5', CountMethod::Native],
        'App\\Ai\\Agents\\ResearchAgent' => [35, 'gpt-5', CountMethod::Estimated],
        'App\\Ai\\Agents\\SummaryAgent' => [60, 'claude-haiku-5-5', CountMethod::Calibrated],
    ];

    public function run(): void
    {
        $start = CarbonImmutable::today()->subDays(6)->setTime(8, 0);

        foreach (self::AGENTS as $agent => [$requests, $model, $method]) {
            for ($i = 0; $i < $requests; $i++) {
                $factory = LedgerEntryFactory::new()
                    ->at($start->addMinutes(random_int(0, 6 * 24 * 60)))
                    ->forAgent($agent)
                    ->forModel($model)
                    ->countMethod($method)
                    ->withTags(['tenant_id' => random_int(1, 5)]);

                $roll = random_int(1, 100);

                match (true) {
                    $roll <= 3 => $factory->blocked()->create(),
                    $roll <= 9 => $factory->warned()->create(),
                    $roll <= 11 => $factory->failed()->create(),
                    $roll <= 13 => $factory->abandoned()->create(),
                    default => $factory->create(['estimated_input_tokens' => random_int(2_000, 40_000)]),
                };
            }
        }
    }
}
