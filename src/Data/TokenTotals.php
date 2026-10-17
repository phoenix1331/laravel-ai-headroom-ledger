<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Data;

/**
 * Token counts summed over one or more requests. Actual figures cover only
 * requests whose usage was reported; estimatedInput covers every counted one.
 */
final readonly class TokenTotals
{
    public function __construct(
        public int $estimatedInput = 0,
        public int $input = 0,
        public int $output = 0,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
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
        );
    }

    /**
     * @return array{estimated_input: int, input: int, output: int, cache_read: int, cache_write: int}
     */
    public function toArray(): array
    {
        return [
            'estimated_input' => $this->estimatedInput,
            'input' => $this->input,
            'output' => $this->output,
            'cache_read' => $this->cacheRead,
            'cache_write' => $this->cacheWrite,
        ];
    }
}
