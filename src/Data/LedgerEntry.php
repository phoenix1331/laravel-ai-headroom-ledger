<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Data;

use Carbon\CarbonImmutable;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroomLedger\Enums\Outcome;

/**
 * One AI provider call as the ledger recorded it. Figures that were never
 * reported are null, not zero: a blocked or counted-only request has an
 * estimate but no usage.
 */
final readonly class LedgerEntry
{
    /**
     * @param  array<string, int>|null  $breakdown  Estimated tokens per part (instructions, history, tools, attachments, prompt).
     * @param  array<string, string>  $tags  Allowlisted context tags.
     */
    public function __construct(
        public string $requestId,
        public ?string $invocationId,
        public ?string $agent,
        public ?string $providerCounted,
        public ?string $modelCounted,
        public ?string $providerUsed,
        public ?string $modelUsed,
        public ?CountMethod $countMethod,
        public ?int $estimatedInputTokens,
        public ?array $breakdown,
        public ?int $contextWindow,
        public ?float $percentUsed,
        public ?int $actualInputTokens,
        public ?int $outputTokens,
        public ?int $cacheReadTokens,
        public ?int $cacheWriteTokens,
        public ?Money $estimatedCost,
        public ?Money $actualCost,
        public bool $streamed,
        public Outcome $outcome,
        public ?string $outcomeReason,
        public ?CarbonImmutable $countedAt,
        public ?CarbonImmutable $usedAt,
        public array $tags = [],
    ) {}

    /**
     * Whether the provider reported usage, so actual tokens and cost are known.
     */
    public function hasUsage(): bool
    {
        return $this->usedAt !== null;
    }

    /**
     * The actual cost where usage was reported, else the estimate (flagged as estimated).
     */
    public function cost(): ?Money
    {
        return $this->actualCost ?? $this->estimatedCost;
    }

    /**
     * The provider that answered, else the one counted against.
     */
    public function provider(): ?string
    {
        return $this->providerUsed ?? $this->providerCounted;
    }

    /**
     * The model that answered, else the one counted against.
     */
    public function model(): ?string
    {
        return $this->modelUsed ?? $this->modelCounted;
    }

    /**
     * Actual input tokens minus the estimate: positive when Headroom under-estimated. Null without both figures.
     */
    public function estimateError(): ?int
    {
        return $this->actualInputTokens === null || $this->estimatedInputTokens === null
            ? null
            : $this->actualInputTokens - $this->estimatedInputTokens;
    }

    public function tokens(): TokenTotals
    {
        return new TokenTotals(
            $this->estimatedInputTokens ?? 0,
            $this->actualInputTokens ?? 0,
            $this->outputTokens ?? 0,
            $this->cacheReadTokens ?? 0,
            $this->cacheWriteTokens ?? 0,
            requests: 1,
            requestsWithUsage: $this->hasUsage() ? 1 : 0,
        );
    }
}
