<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Phoenix1331\AiHeadroom\Enums\LimitType;
use Phoenix1331\AiHeadroom\Events\HeadroomLow;
use Phoenix1331\AiHeadroom\Events\RequestBlocked;
use Phoenix1331\AiHeadroom\Events\RequestFailed;
use Phoenix1331\AiHeadroomLedger\Enums\Outcome;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordHeadroomLow;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordRequestBlocked;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordRequestFailed;
use Phoenix1331\AiHeadroomLedger\Models\Entry;

function theEntry(): Entry
{
    return Entry::query()->findOrFail('01JREQUEST000000000000000A');
}

it('listens for the outcome events', function (): void {
    Event::fake();

    Event::assertListening(RequestBlocked::class, RecordRequestBlocked::class);
    Event::assertListening(HeadroomLow::class, RecordHeadroomLow::class);
    Event::assertListening(RequestFailed::class, RecordRequestFailed::class);
});

describe('blocked', function (): void {
    it('records the count carried by the block when no count was recorded', function (): void {
        event(requestBlocked(['total' => 210_000, 'breakdown' => ['history' => 210_000]]));

        expect(theEntry())
            ->outcome->toBe(Outcome::Blocked)
            ->outcome_reason->toBe('context_window')
            ->model_counted->toBe('claude-sonnet-5-5')
            ->estimated_input_tokens->toBe(210_000)
            ->percent_used->toBe(105.0)
            ->estimated_cost->not->toBeNull()
            ->counted_at->toEqual(CarbonImmutable::parse('2026-10-17 09:30:00'))
            ->used_at->toBeNull()
            ->actual_cost->toBeNull();
    });

    it('blocks a counted request', function (): void {
        event(tokensCounted());
        event(requestBlocked(['limit' => LimitType::Cost]));

        expect(theEntry())
            ->outcome->toBe(Outcome::Blocked)
            ->outcome_reason->toBe('max_cost');
    });

    it('prefers the invocation on the event over the one on the count', function (): void {
        event(requestBlocked(['invocationId' => null, 'eventInvocationId' => 'run-7']));

        expect(theEntry()->invocation_id)->toBe('run-7');
    });
});

describe('warned', function (): void {
    it('records a warning that arrives before anything else', function (): void {
        event(headroomLow());

        expect(theEntry())
            ->outcome->toBe(Outcome::Warned)
            ->outcome_reason->toBe('max_percent_of_context')
            ->occurred_on->toDateString()->toBe('2026-10-17')
            ->model_counted->toBeNull();
    });

    it('stores the limit, never the generated description', function (): void {
        event(headroomLow());

        expect(theEntry()->outcome_reason)->not->toContain('%');
    });

    it('warns a counted request', function (): void {
        event(tokensCounted());
        event(headroomLow());

        expect(theEntry()->outcome)->toBe(Outcome::Warned);
    });

    it('records a warning without an invocation', function (): void {
        event(headroomLow(['invocationId' => null]));
        event(headroomLow(['invocationId' => null]));

        expect(Entry::query()->count())->toBe(1)
            ->and(theEntry()->outcome)->toBe(Outcome::Warned);
    });

    it('keeps the highest-ranked limit when several are breached', function (array $limits): void {
        foreach ($limits as $limit) {
            event(headroomLow(['limit' => $limit]));
        }

        expect(theEntry()->outcome_reason)->toBe('max_percent_of_context');
    })->with([
        'percent first' => [[LimitType::PercentOfContext, LimitType::Cost]],
        'cost first' => [[LimitType::Cost, LimitType::PercentOfContext]],
    ]);

    it('does not override a block', function (): void {
        event(requestBlocked());
        event(headroomLow());

        expect(theEntry()->outcome)->toBe(Outcome::Blocked);
    });
});

describe('failed', function (): void {
    it('records the exception class and status only', function (): void {
        event(requestFailed());

        expect(theEntry())
            ->outcome->toBe(Outcome::Failed)
            ->outcome_reason->toBe('Illuminate\\Http\\Client\\RequestException:529')
            ->provider_counted->toBe('anthropic')
            ->model_counted->toBe('claude-sonnet-5-5');
    });

    it('records a failure without an HTTP status', function (): void {
        event(tokensCounted());
        event(requestFailed(['exception' => 'RuntimeException', 'httpStatus' => null]));

        expect(theEntry())
            ->outcome->toBe(Outcome::Failed)
            ->outcome_reason->toBe('RuntimeException');
    });

    it('keeps the counted model when the failure arrives second', function (): void {
        event(tokensCounted());
        event(requestFailed(['provider' => 'openai', 'model' => 'gpt-5']));

        expect(theEntry()->model_counted)->toBe('claude-sonnet-5-5');
    });

    it('records a failure without an invocation', function (): void {
        event(tokensCounted());
        event(requestFailed(['invocationId' => null]));

        expect(theEntry()->invocation_id)->toBe('0199f3a2-0000-7000-8000-000000000001');
    });
});

it('clears a failure reason once usage proves the request was answered', function (): void {
    event(requestFailed());
    event(tokensUsed());

    expect(theEntry())
        ->outcome->toBe(Outcome::Completed)
        ->outcome_reason->toBeNull();
});

/*
 * Queued listeners can run in any order. Every ordering of each mix of events
 * must leave exactly the same row.
 */
dataset('event mixes', [
    'counted, blocked' => [['counted', 'blocked'], Outcome::Blocked, 'context_window'],
    'counted, used' => [['counted', 'used'], Outcome::Completed, null],
    'counted, warned, used' => [['counted', 'warned', 'used'], Outcome::Warned, 'max_percent_of_context'],
    'counted, failed' => [['counted', 'failed'], Outcome::Failed, 'Illuminate\\Http\\Client\\RequestException:529'],
    'counted, failed, used (failover)' => [['counted', 'failed', 'used'], Outcome::Completed, null],
    'counted, warned, failed' => [['counted', 'warned', 'failed'], Outcome::Warned, 'max_percent_of_context'],
    'counted, warned, failed, used' => [['counted', 'warned', 'failed', 'used'], Outcome::Warned, 'max_percent_of_context'],
    'counted, two warnings, used' => [['counted', 'warned', 'warned cost', 'used'], Outcome::Warned, 'max_percent_of_context'],
]);

it('settles to the same row in every arrival order', function (array $mix, Outcome $outcome, ?string $reason): void {
    // TokensCounted::from() stamps the current time; freeze it so rows differ only by event order.
    CarbonImmutable::setTestNow('2026-10-17 09:30:00');

    $events = [
        'counted' => fn (string $id) => tokensCounted(['requestId' => $id]),
        'used' => fn (string $id) => tokensUsed(['requestId' => $id]),
        'blocked' => fn (string $id) => requestBlocked(['requestId' => $id]),
        'warned' => fn (string $id) => headroomLow(['requestId' => $id]),
        'warned cost' => fn (string $id) => headroomLow(['requestId' => $id, 'limit' => LimitType::Cost]),
        'failed' => fn (string $id) => requestFailed(['requestId' => $id]),
    ];

    $orders = permutations($mix);

    foreach ($orders as $i => $order) {
        foreach ($order as $name) {
            event($events[$name]("order-{$i}"));
        }
    }

    $rows = Entry::query()->orderBy('request_id')->get()
        ->map(fn (Entry $entry): array => collect($entry->getAttributes())->except('request_id')->all());

    expect($rows)->toHaveCount(count($orders))
        ->and($rows->unique(fn (array $row): string => serialize($row)))->toHaveCount(1)
        ->and(Entry::query()->first())
        ->outcome->toBe($outcome)
        ->outcome_reason->toBe($reason);
})->with('event mixes');
