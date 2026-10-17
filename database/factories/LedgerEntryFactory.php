<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Database\Factories;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Enums\LimitType;
use Phoenix1331\AiHeadroomLedger\Enums\Outcome;
use Phoenix1331\AiHeadroomLedger\Models\Entry;

/**
 * Realistic ledger entries for tests, demos and dashboards. Needs no Faker,
 * so apps can seed with it outside dev dependencies.
 *
 * States set only what they change; everything derived (breakdown, percent
 * used, actual usage, costs, date) is filled in after all states apply, so
 * every entry is internally consistent whatever the combination.
 *
 *     LedgerEntryFactory::new()->count(50)->create();
 *     LedgerEntryFactory::new()->blocked()->forAgent(SupportAgent::class)->create();
 *     LedgerEntryFactory::new()->count(3)->inRun()->withTags(['tenant_id' => 42])->create();
 *
 * @extends Factory<Entry>
 */
class LedgerEntryFactory extends Factory
{
    /** @var class-string<Entry> */
    protected $model = Entry::class;

    /**
     * Context window and price per million input and output tokens. Prices
     * are illustrative, not a pricing source.
     *
     * @var array<string, array{provider: string, window: int, input: float, output: float}>
     */
    public const array MODELS = [
        'claude-sonnet-5-5' => ['provider' => 'anthropic', 'window' => 200_000, 'input' => 3.0, 'output' => 15.0],
        'claude-haiku-5-5' => ['provider' => 'anthropic', 'window' => 200_000, 'input' => 1.0, 'output' => 5.0],
        'gpt-5' => ['provider' => 'openai', 'window' => 400_000, 'input' => 1.25, 'output' => 10.0],
        'gemini-2.5-flash' => ['provider' => 'gemini', 'window' => 1_000_000, 'input' => 0.3, 'output' => 2.5],
    ];

    /** Used for models not in MODELS. */
    private const array UNKNOWN_MODEL = ['provider' => 'custom', 'window' => 128_000, 'input' => 1.0, 'output' => 5.0];

    /**
     * Example agent class names; they need not exist.
     *
     * @var list<string>
     */
    public const array AGENTS = [
        'App\\Ai\\Agents\\SupportAgent',
        'App\\Ai\\Agents\\ResearchAgent',
        'App\\Ai\\Agents\\SummaryAgent',
    ];

    /** Output tokens priced into the pre-flight estimate. */
    public const int ESTIMATED_OUTPUT_TOKENS = 1_024;

    /**
     * A completed request: counted, sent and answered.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $model = array_rand(self::MODELS);
        $countedAt = Carbon::now()->subMinutes(random_int(0, 30 * 24 * 60));

        return [
            'request_id' => (string) Str::ulid(),
            'invocation_id' => (string) Str::uuid7(),
            'agent' => Arr::random(self::AGENTS),
            'provider_counted' => self::MODELS[$model]['provider'],
            'model_counted' => $model,
            'count_method' => Arr::random(CountMethod::cases()),
            'estimated_input_tokens' => random_int(800, 60_000),
            'currency' => 'USD',
            'outcome' => Outcome::Completed,
            'counted_at' => $countedAt,
            'used_at' => $countedAt,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Entry $entry): void {
            $this->derive($entry);
        });
    }

    /**
     * Counted only: no usage reported, for example Headroom::for() without recordUsage().
     */
    public function counted(): static
    {
        return $this->state(['outcome' => Outcome::Counted, 'used_at' => null]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'outcome' => Outcome::Completed,
            'used_at' => $attributes['used_at'] ?? Carbon::now(),
        ]);
    }

    /**
     * Crossed a soft limit (HeadroomLow) at 80-95% of context, and was still sent and answered.
     */
    public function warned(): static
    {
        return $this->state(fn (): array => [
            'estimated_input_tokens' => null,
            'percent_used' => random_int(8_000, 9_500) / 100,
            'outcome' => Outcome::Warned,
            'outcome_reason' => LimitType::PercentOfContext->value,
        ]);
    }

    /**
     * Did not fit the context window (RequestBlocked) and was never sent.
     */
    public function blocked(): static
    {
        return $this->state(fn (): array => [
            'estimated_input_tokens' => null,
            'percent_used' => random_int(10_100, 13_000) / 100,
            'outcome' => Outcome::Blocked,
            'outcome_reason' => LimitType::ContextWindow->value,
            'used_at' => null,
        ]);
    }

    /**
     * The provider call failed (RequestFailed). The reason holds the exception class and HTTP status, never a message.
     */
    public function failed(string $exception = 'Illuminate\\Http\\Client\\RequestException', ?int $status = 529): static
    {
        return $this->state([
            'outcome' => Outcome::Failed,
            'outcome_reason' => $status === null ? $exception : $exception.':'.$status,
            'used_at' => null,
        ]);
    }

    public function streamed(): static
    {
        return $this->state(['streamed' => true]);
    }

    /**
     * A stream the client walked away from: counted, never completed.
     */
    public function abandoned(): static
    {
        return $this->counted()->streamed();
    }

    /**
     * Cache tokens were reported but the model registry had no cache price.
     */
    public function approximateCost(): static
    {
        return $this->state(['cost_approximate' => true]);
    }

    /**
     * Answered by a different model than the one counted, as after failover.
     */
    public function failover(string $model = 'gpt-5', ?string $provider = null): static
    {
        return $this->state([
            'provider_used' => $provider ?? self::spec($model)['provider'],
            'model_used' => $model,
        ]);
    }

    /**
     * Steps of one agent run: the same invocation ID and agent, a minute apart.
     */
    public function inRun(?string $invocationId = null, ?string $agent = null): static
    {
        $start = Carbon::now()->subMinutes(random_int(60, 30 * 24 * 60));

        return $this
            ->state([
                'invocation_id' => $invocationId ?? (string) Str::uuid7(),
                'agent' => $agent ?? Arr::random(self::AGENTS),
            ])
            ->sequence(fn (Sequence $sequence): array => self::timing($start->copy()->addMinutes($sequence->index)));
    }

    public function forAgent(string $agent): static
    {
        return $this->state(['agent' => $agent]);
    }

    /**
     * Counted against this model. Models outside MODELS get a 128k window and default prices.
     */
    public function forModel(string $model, ?string $provider = null): static
    {
        return $this->state([
            'provider_counted' => $provider ?? self::spec($model)['provider'],
            'model_counted' => $model,
        ]);
    }

    public function countMethod(CountMethod $method): static
    {
        return $this->state(['count_method' => $method]);
    }

    public function inCurrency(string $currency): static
    {
        return $this->state(['currency' => strtoupper($currency)]);
    }

    public function at(DateTimeInterface $countedAt): static
    {
        return $this->state(fn (): array => self::timing(Carbon::instance($countedAt)));
    }

    /**
     * Context tags stored with the entry. Bypasses the allowlist, which applies to recording only.
     *
     * @param  array<string, scalar>  $tags
     */
    public function withTags(array $tags): static
    {
        return $this->afterCreating(function (Entry $entry) use ($tags): void {
            foreach ($tags as $key => $value) {
                $entry->tags()->create([
                    'key' => $key,
                    'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                ]);
            }
        });
    }

    /**
     * @return array{provider: string, window: int, input: float, output: float}
     */
    public static function spec(?string $model): array
    {
        return self::MODELS[$model ?? ''] ?? self::UNKNOWN_MODEL;
    }

    /**
     * Fill in every figure a state did not set, consistently with the ones it did.
     */
    private function derive(Entry $entry): void
    {
        $spec = self::spec($entry->model_counted);

        $entry->context_window ??= $spec['window'];
        $entry->estimated_input_tokens ??= (int) round($entry->context_window * ($entry->percent_used ?? 10.0) / 100);
        $entry->percent_used = round($entry->estimated_input_tokens / $entry->context_window * 100, 2);
        $entry->breakdown ??= self::breakdown($entry->estimated_input_tokens);
        $entry->estimated_cost ??= self::microCost($entry->estimated_input_tokens, self::ESTIMATED_OUTPUT_TOKENS, $spec);

        $countedAt = $entry->counted_at ?? Carbon::now()->toImmutable();
        $entry->occurred_on = $countedAt->startOfDay();

        if ($entry->used_at === null) {
            return;
        }

        $entry->used_at = $countedAt->addSeconds(random_int(1, 40));

        $entry->provider_used ??= $entry->provider_counted;
        $entry->model_used ??= $entry->model_counted;
        $entry->actual_input_tokens ??= (int) round($entry->estimated_input_tokens * random_int(90, 110) / 100);
        $entry->output_tokens ??= random_int(50, 2_000);
        $entry->cache_read_tokens ??= $entry->cost_approximate ? (int) round($entry->actual_input_tokens * 0.6) : 0;
        $entry->cache_write_tokens ??= 0;
        $entry->actual_cost ??= self::microCost($entry->actual_input_tokens, $entry->output_tokens, self::spec($entry->model_used));
    }

    /**
     * @return array{instructions: int, history: int, tools: int, attachments: int, prompt: int}
     */
    private static function breakdown(int $tokens): array
    {
        $instructions = (int) round($tokens * 0.15);
        $tools = (int) round($tokens * 0.1);
        $prompt = (int) round($tokens * 0.05);

        return [
            'instructions' => $instructions,
            'history' => $tokens - $instructions - $tools - $prompt,
            'tools' => $tools,
            'attachments' => 0,
            'prompt' => $prompt,
        ];
    }

    /**
     * A price per million tokens times a token count is already in micro-units.
     *
     * @param  array{provider: string, window: int, input: float, output: float}  $spec
     */
    private static function microCost(int $inputTokens, int $outputTokens, array $spec): int
    {
        return (int) round($inputTokens * $spec['input'] + $outputTokens * $spec['output']);
    }

    /**
     * Usage, when there is any, is re-timed from counted_at in derive().
     *
     * @return array{counted_at: Carbon}
     */
    private static function timing(Carbon $countedAt): array
    {
        return ['counted_at' => $countedAt];
    }
}
