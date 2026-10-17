<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * How close requests came to their context window, and how many hit a
 * limit. Percentages come from the pre-flight count, so blocked requests
 * (which may exceed 100%) count too.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ContextUsage implements Arrayable
{
    public function __construct(
        public int $requests = 0,
        public int $warned = 0,
        public int $blocked = 0,
        public int $failed = 0,
        public int $samples = 0,
        public float $percentUsedSum = 0.0,
        public float $maxPercentUsed = 0.0,
    ) {}

    /**
     * Average share of the context window used, over requests with a count.
     */
    public function averagePercentUsed(): ?float
    {
        return $this->samples === 0 ? null : round($this->percentUsedSum / $this->samples, 2);
    }

    public function plus(self $other): self
    {
        return new self(
            $this->requests + $other->requests,
            $this->warned + $other->warned,
            $this->blocked + $other->blocked,
            $this->failed + $other->failed,
            $this->samples + $other->samples,
            round($this->percentUsedSum + $other->percentUsedSum, 2),
            max($this->maxPercentUsed, $other->maxPercentUsed),
        );
    }

    /**
     * @return array{requests: int, warned: int, blocked: int, failed: int, average_percent_used: float|null, max_percent_used: float|null}
     */
    public function toArray(): array
    {
        return [
            'requests' => $this->requests,
            'warned' => $this->warned,
            'blocked' => $this->blocked,
            'failed' => $this->failed,
            'average_percent_used' => $this->averagePercentUsed(),
            'max_percent_used' => $this->samples === 0 ? null : round($this->maxPercentUsed, 2),
        ];
    }
}
