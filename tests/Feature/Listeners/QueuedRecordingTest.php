<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Phoenix1331\AiHeadroomLedger\Enums\Outcome;
use Phoenix1331\AiHeadroomLedger\Events\LedgerEntryCompleted;
use Phoenix1331\AiHeadroomLedger\Events\LedgerEntryRecorded;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordHeadroomLow;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordRequestBlocked;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordRequestFailed;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordsToLedger;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordTokensCounted;
use Phoenix1331\AiHeadroomLedger\Listeners\RecordTokensUsed;
use Phoenix1331\AiHeadroomLedger\Models\Entry;

/**
 * Run a pushed job as a worker would: through serialisation, then the listener.
 */
function runQueuedJob(CallQueuedListener $job): void
{
    /** @var CallQueuedListener $job */
    $job = unserialize(serialize($job));

    app($job->class)->{$job->method}(...array_values($job->data));
}

/**
 * @return list<CallQueuedListener>
 */
function pushedLedgerJobs(): array
{
    return Queue::pushed(CallQueuedListener::class)->values()->all();
}

it('queues every listener', function (string $listener): void {
    expect(new ReflectionClass($listener))->isSubclassOf(RecordsToLedger::class)->toBeTrue()
        ->and(is_subclass_of($listener, ShouldQueue::class))->toBeTrue();
})->with([RecordTokensCounted::class, RecordTokensUsed::class, RecordRequestBlocked::class, RecordHeadroomLow::class, RecordRequestFailed::class]);

it('uses the app default connection and queue unless configured', function (): void {
    $listener = app(RecordTokensCounted::class);

    expect($listener->viaConnection())->toBeNull()
        ->and($listener->viaQueue())->toBeNull();
});

it('uses the configured connection and queue', function (): void {
    config(['headroom-ledger.queue' => ['connection' => 'redis', 'name' => 'ledger']]);
    $listener = app(RecordTokensCounted::class);

    expect($listener->viaConnection())->toBe('redis')
        ->and($listener->viaQueue())->toBe('ledger');
});

it('pushes recording onto the configured queue instead of writing during the request', function (): void {
    Queue::fake();
    config(['headroom-ledger.queue.name' => 'ledger']);

    event(tokensCounted());
    event(tokensUsed());

    Queue::assertPushedOn('ledger', CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === RecordTokensCounted::class);
    Queue::assertPushedOn('ledger', CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === RecordTokensUsed::class);
    expect(Entry::query()->count())->toBe(0);
});

it('retries a failed job with backoff', function (): void {
    Queue::fake();

    event(tokensCounted());

    expect(pushedLedgerJobs()[0])
        ->tries->toBe(3)
        ->backoff->toBe([5, 30, 120]);
});

it('settles the same when queued jobs run in reverse order', function (): void {
    Queue::fake();

    event(tokensCounted());
    event(headroomLow());
    event(tokensUsed());

    foreach (array_reverse(pushedLedgerJobs()) as $job) {
        runQueuedJob($job);
    }

    expect(Entry::query()->findOrFail('01JREQUEST000000000000000A'))
        ->outcome->toBe(Outcome::Warned)
        ->counted_at->not->toBeNull()
        ->actual_cost->toBe(8_250);
});

it('records every step of a queued agent run', function (): void {
    Queue::fake();

    foreach (['step-1', 'step-2', 'step-3'] as $step) {
        event(tokensCounted(['requestId' => $step, 'invocationId' => 'run-1']));
        event(tokensUsed(['requestId' => $step, 'invocationId' => 'run-1']));
    }

    foreach (pushedLedgerJobs() as $job) {
        runQueuedJob($job);
    }

    expect(Entry::query()->where('invocation_id', 'run-1')->where('outcome', Outcome::Completed)->count())->toBe(3);
});

it('reports instead of throwing when recording inline, so the AI request is never broken', function (): void {
    Exceptions::fake();
    Schema::drop('headroom_ledger_tags');
    Schema::drop('headroom_ledger_entries');

    event(tokensCounted());

    Exceptions::assertReported(QueryException::class);
});

it('treats the app default connection as inline when it is sync', function (): void {
    Exceptions::fake();
    config(['queue.default' => 'sync', 'headroom-ledger.queue.connection' => null]);
    Schema::drop('headroom_ledger_tags');
    Schema::drop('headroom_ledger_entries');

    app(RecordTokensCounted::class)->handle(tokensCounted());

    Exceptions::assertReported(QueryException::class);
});

it('throws when running as a queued job, so the queue can retry', function (): void {
    config(['headroom-ledger.queue.connection' => 'database']);
    Schema::drop('headroom_ledger_tags');
    Schema::drop('headroom_ledger_entries');

    app(RecordTokensCounted::class)->handle(tokensCounted());
})->throws(QueryException::class);

it('records nothing once disabled at runtime', function (): void {
    config(['headroom-ledger.enabled' => false]);

    event(tokensCounted());
    event(tokensUsed());

    expect(Entry::query()->count())->toBe(0);
});

it('announces every write with the outcome after it', function (): void {
    $recorded = [];
    Event::listen(LedgerEntryRecorded::class, function (LedgerEntryRecorded $event) use (&$recorded): void {
        $recorded[] = $event;
    });

    event(tokensCounted());
    event(headroomLow());
    event(tokensUsed());

    expect(array_map(fn (LedgerEntryRecorded $event): Outcome => $event->outcome, $recorded))
        ->toBe([Outcome::Counted, Outcome::Warned, Outcome::Warned])
        ->and($recorded[0])
        ->requestId->toBe('01JREQUEST000000000000000A')
        ->invocationId->toBe('0199f3a2-0000-7000-8000-000000000001');
});

it('announces completion once usage is recorded', function (): void {
    $completed = [];
    Event::listen(LedgerEntryCompleted::class, function (LedgerEntryCompleted $event) use (&$completed): void {
        $completed[] = $event;
    });

    event(tokensCounted());
    event(requestFailed());
    event(tokensUsed());

    expect($completed)->toHaveCount(1)
        ->and($completed[0])
        ->requestId->toBe('01JREQUEST000000000000000A')
        ->outcome->toBe(Outcome::Completed);
});

it('announces completion alone when only completion is listened for', function (): void {
    $completed = 0;
    Event::listen(LedgerEntryCompleted::class, function () use (&$completed): void {
        $completed++;
    });

    event(tokensUsed());

    expect($completed)->toBe(1);
});

it('skips the read-back when nobody is listening', function (): void {
    DB::enableQueryLog();

    event(tokensCounted());

    $selects = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'));

    expect($selects)->toBeEmpty();
});

it('announces nothing for a row removed before it could be read back', function (): void {
    $recorded = 0;
    Event::listen(LedgerEntryRecorded::class, function () use (&$recorded): void {
        $recorded++;
    });

    // Simulate pruning or forgetTag() deleting the row between the write and the read-back.
    DB::listen(function ($query): void {
        if (str_contains(strtolower($query->sql), 'insert into')) {
            DB::table('headroom_ledger_entries')->delete();
        }
    });

    event(tokensCounted());

    expect($recorded)->toBe(0);
});
