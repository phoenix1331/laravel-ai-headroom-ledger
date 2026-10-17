<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Token counts summed over one or more requests. estimatedInput covers every
 * counted request; input, output and cache figures cover only the
 * requestsWithUsage whose usage was reported, so a request that never
 * reported usage is not counted as zero tokens.
 *
 * @implements Arrayable<string, int>
 */
final readonly class TokenTotals implements Arrayable
{
    public function __construct(
        public int $estimatedInput = 0,
        public int $input = 0,
        public int $output = 0,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
        public int $requests = 0,
        public int $requestsWithUsage = 0,
    ) {}

    /**
     * Input tokens served from a prompt cache. Already included in $input.
     */
    public function cached(): int
    {
        return $this->cacheRead;
    }

    public function plus(self $other): self
    {
        return new self(
            $this->estimatedInput + $other->estimatedInput,
            $this->input + $other->input,
            $this->output + $other->output,
            $this->cacheRead + $other->cacheRead,
            $this->cacheWrite + $other->cacheWrite,
            $this->requests + $other->requests,
            $this->requestsWithUsage + $other->requestsWithUsage,
        );
    }

    /**
     * @return array{estimated_input: int, input: int, output: int, cache_read: int, cache_write: int, requests: int, requests_with_usage: int}
     */
    public function toArray(): array
    {
        return [
            'estimated_input' => $this->estimatedInput,
            'input' => $this->input,
            'output' => $this->output,
            'cache_read' => $this->cacheRead,
            'cache_write' => $this->cacheWrite,
            'requests' => $this->requests,
            'requests_with_usage' => $this->requestsWithUsage,
        ];
    }
}
