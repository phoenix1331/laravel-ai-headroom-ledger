<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Data;

/**
 * One group of a breakdown: the model, agent, day, run or tag value, and its
 * result. $key is null for requests without a value, such as no agent.
 *
 * @template TValue
 */
final readonly class Group
{
    /**
     * @param  TValue  $value
     */
    public function __construct(
        public ?string $key,
        public mixed $value,
    ) {}
}
