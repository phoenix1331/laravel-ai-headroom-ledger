<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Phoenix1331\AiHeadroom\Data\Cost;
use Phoenix1331\AiHeadroom\Data\TokenCount;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroom\Enums\Currency;
use Phoenix1331\AiHeadroom\Events\TokensCounted;
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
