<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * What a set of requests cost: actual where usage was reported, else the
 * pre-flight estimate, with the two parts kept visible. Every part is grouped
 * by currency and never converted.
 *
 * Requests with neither usage nor an estimate (recorded only from a warning
 * or failure) count in $requests but carry no amount.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class SpendSummary implements Arrayable
{
    /**
     * @param  Spend  $total  actual plus estimatedOnly; flagged estimated when it includes any estimate.
     * @param  Spend  $actual  Reported cost of requests whose usage was recorded.
     * @param  Spend  $estimatedOnly  Estimated cost of requests that never reported usage.
     */
    public function __construct(
        public Spend $total = new Spend,
        public Spend $actual = new Spend,
        public Spend $estimatedOnly = new Spend,
        public int $requests = 0,
        public int $requestsWithUsage = 0,
        public int $estimateOnlyRequests = 0,
    ) {}

    /**
     * Whether any part of the total is an estimate rather than reported cost.
     */
    public function isEstimated(): bool
    {
        return $this->estimateOnlyRequests > 0;
    }

    /**
     * @return array{total: list<array<string, mixed>>, actual: list<array<string, mixed>>, estimated_only: list<array<string, mixed>>, requests: int, requests_with_usage: int, estimate_only_requests: int}
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total->toArray(),
            'actual' => $this->actual->toArray(),
            'estimated_only' => $this->estimatedOnly->toArray(),
            'requests' => $this->requests,
            'requests_with_usage' => $this->requestsWithUsage,
            'estimate_only_requests' => $this->estimateOnlyRequests,
        ];
    }
}
