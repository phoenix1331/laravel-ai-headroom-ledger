<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Data;

use Carbon\CarbonImmutable;

/**
 * Every recorded step of one agent run (one SDK invocation), with totals.
 */
final readonly class RunSummary
{
    public function __construct(
        public string $invocationId,
        public ?string $agent,
        public LedgerEntries $steps,
        public TokenTotals $tokens,
        public Spend $spend,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $endedAt,
    ) {}

    public static function of(string $invocationId, LedgerEntries $steps): self
    {
        $agent = null;
        $times = [];

        foreach ($steps as $step) {
            $agent ??= $step->agent;
            array_push($times, ...array_filter([$step->countedAt, $step->usedAt]));
        }

        usort($times, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return new self(
            $invocationId,
            $agent,
            $steps,
            $steps->tokens(),
            $steps->spend(),
            $times[0] ?? null,
            $times === [] ? null : $times[count($times) - 1],
        );
    }

    public function stepCount(): int
    {
        return $this->steps->count();
    }
}
