<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Data;

use Illuminate\Contracts\Support\Arrayable;
use Phoenix1331\AiHeadroom\Enums\CountMethod;

/**
 * How close Headroom's pre-flight estimates came to the input tokens the
 * provider reported, for one model and count method (or overall, when both
 * are null). Built from requests that reported usage from the model that was
 * counted; failover answers are left out, since another model's tokeniser
 * produced them.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class AccuracyStat implements Arrayable
{
    public function __construct(
        public ?string $model,
        public ?CountMethod $countMethod,
        public int $samples,
        public int $estimatedTokens,
        public int $actualTokens,
        public int $absoluteError,
    ) {}

    /**
     * Average tokens between estimate and actual, either way.
     */
    public function meanAbsoluteError(): ?float
    {
        return $this->samples === 0 ? null : $this->absoluteError / $this->samples;
    }

    /**
     * Total absolute error as a percentage of actual tokens (weighted, so
     * small requests cannot dominate).
     */
    public function errorPercent(): ?float
    {
        return $this->actualTokens === 0 ? null : round($this->absoluteError / $this->actualTokens * 100, 2);
    }

    /**
     * Signed difference as a percentage of actual tokens: positive when
     * estimates ran low (under-estimated), negative when they ran high.
     */
    public function biasPercent(): ?float
    {
        return $this->actualTokens === 0 ? null : round(($this->actualTokens - $this->estimatedTokens) / $this->actualTokens * 100, 2);
    }

    public function plus(self $other): self
    {
        return new self(
            $this->model === $other->model ? $this->model : null,
            $this->countMethod === $other->countMethod ? $this->countMethod : null,
            $this->samples + $other->samples,
            $this->estimatedTokens + $other->estimatedTokens,
            $this->actualTokens + $other->actualTokens,
            $this->absoluteError + $other->absoluteError,
        );
    }

    /**
     * @return array{model: string|null, count_method: string|null, samples: int, estimated_tokens: int, actual_tokens: int, absolute_error: int, mean_absolute_error: float|null, error_percent: float|null, bias_percent: float|null}
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'count_method' => $this->countMethod?->value,
            'samples' => $this->samples,
            'estimated_tokens' => $this->estimatedTokens,
            'actual_tokens' => $this->actualTokens,
            'absolute_error' => $this->absoluteError,
            'mean_absolute_error' => $this->meanAbsoluteError(),
            'error_percent' => $this->errorPercent(),
            'bias_percent' => $this->biasPercent(),
        ];
    }
}
