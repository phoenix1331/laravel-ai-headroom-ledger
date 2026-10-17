<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Phoenix1331\AiHeadroom\Data\Cost;
use Phoenix1331\AiHeadroom\Data\TokenCount;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Enums\Currency;
use Phoenix1331\AiHeadroom\Enums\LimitType;
use Phoenix1331\AiHeadroom\Events\HeadroomLow;
use Phoenix1331\AiHeadroom\Events\RequestBlocked;
use Phoenix1331\AiHeadroom\Events\RequestFailed;
use Phoenix1331\AiHeadroom\Events\TokensCounted;
use Phoenix1331\AiHeadroom\Events\TokensUsed;
use Phoenix1331\AiHeadroomLedger\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature');

/**
 * A real Headroom count, as the guard or Headroom::for() would build it.
 *
 * @param  array<string, mixed>  $overrides
 */
function tokenCount(array $overrides = []): TokenCount
{
    $o = $overrides + [
        'total' => 1_200,
        'breakdown' => ['instructions' => 300, 'history' => 600, 'tools' => 100, 'attachments' => 0, 'prompt' => 200],
        'method' => CountMethod::Estimated,
        'confidence' => 0.9,
        'model' => 'claude-sonnet-5-5',
        'contextWindow' => 200_000,
        'cost' => new Cost(0.0036, 0.01536, Currency::Usd),
        'requestId' => '01JREQUEST000000000000000A',
        'provider' => 'anthropic',
        'agent' => 'App\\Ai\\Agents\\SupportAgent',
        'invocationId' => '0199f3a2-0000-7000-8000-000000000001',
        'context' => [],
    ];

    return new TokenCount(
        $o['total'], $o['breakdown'], $o['method'], $o['confidence'], $o['model'], $o['contextWindow'], $o['cost'],
        $o['requestId'], $o['provider'], $o['agent'], $o['invocationId'], $o['context'],
    );
}

/**
 * @param  array<string, mixed>  $overrides  tokenCount() overrides, plus 'at' for the event time.
 */
function tokensCounted(array $overrides = []): TokensCounted
{
    $event = TokensCounted::from(tokenCount($overrides));

    if (! isset($overrides['at'])) {
        return $event;
    }

    return new TokensCounted(
        $event->requestId, $event->invocationId, $event->agent, $event->provider, $event->model, $event->countMethod,
        $event->estimatedInputTokens, $event->breakdown, $event->contextWindow, $event->percentUsed, $event->estimatedCost,
        $event->context, CarbonImmutable::parse($overrides['at']),
    );
}

/**
 * Usage as Headroom reports it after the provider answers.
 *
 * @param  array<string, mixed>  $overrides
 */
function tokensUsed(array $overrides = []): TokensUsed
{
    $o = $overrides + [
        'requestId' => '01JREQUEST000000000000000A',
        'invocationId' => '0199f3a2-0000-7000-8000-000000000001',
        'agent' => 'App\\Ai\\Agents\\SupportAgent',
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-5-5',
        'countedModel' => 'claude-sonnet-5-5',
        'inputTokens' => 1_250,
        'outputTokens' => 300,
        'cacheReadTokens' => null,
        'cacheWriteTokens' => null,
        'cost' => new Cost(0.00375, 0.0045, Currency::Usd),
        'streamed' => false,
        'countMethod' => CountMethod::Estimated,
        'estimatedInputTokens' => 1_200,
        'context' => [],
        'at' => '2026-10-17 09:30:05',
    ];

    return new TokensUsed(
        $o['requestId'], $o['invocationId'], $o['agent'], $o['provider'], $o['model'], $o['countedModel'],
        $o['inputTokens'], $o['outputTokens'], $o['cacheReadTokens'], $o['cacheWriteTokens'], $o['cost'],
        $o['streamed'], $o['countMethod'], $o['estimatedInputTokens'], $o['context'], CarbonImmutable::parse($o['at']),
    );
}

function requestBlocked(array $overrides = []): RequestBlocked
{
    $count = tokenCount($overrides);
    $limit = $overrides['limit'] ?? LimitType::ContextWindow;

    return new RequestBlocked(
        $count->requestId(), $overrides['eventInvocationId'] ?? $count->invocationId(), $limit, 200_000, 210_000, $count,
        CarbonImmutable::parse($overrides['at'] ?? '2026-10-17 09:30:00'),
    );
}

function headroomLow(array $overrides = []): HeadroomLow
{
    return new HeadroomLow(
        $overrides['requestId'] ?? '01JREQUEST000000000000000A',
        array_key_exists('invocationId', $overrides) ? $overrides['invocationId'] : '0199f3a2-0000-7000-8000-000000000001',
        $overrides['limit'] ?? LimitType::PercentOfContext,
        80.0,
        85.2,
        '85.2% of context used, limit 80%',
        CarbonImmutable::parse($overrides['at'] ?? '2026-10-17 09:30:00'),
    );
}

function requestFailed(array $overrides = []): RequestFailed
{
    return new RequestFailed(
        $overrides['requestId'] ?? '01JREQUEST000000000000000A',
        array_key_exists('invocationId', $overrides) ? $overrides['invocationId'] : '0199f3a2-0000-7000-8000-000000000001',
        $overrides['provider'] ?? 'anthropic',
        $overrides['model'] ?? 'claude-sonnet-5-5',
        $overrides['exception'] ?? 'Illuminate\\Http\\Client\\RequestException',
        array_key_exists('httpStatus', $overrides) ? $overrides['httpStatus'] : 529,
        CarbonImmutable::parse($overrides['at'] ?? '2026-10-17 09:30:01'),
    );
}

/**
 * Every ordering of the given items.
 *
 * @template T
 *
 * @param  list<T>  $items
 * @return list<list<T>>
 */
function permutations(array $items): array
{
    if (count($items) <= 1) {
        return [$items];
    }

    $orders = [];

    foreach ($items as $i => $item) {
        $rest = $items;
        unset($rest[$i]);

        foreach (permutations(array_values($rest)) as $order) {
            $orders[] = [$item, ...$order];
        }
    }

    return $orders;
}
