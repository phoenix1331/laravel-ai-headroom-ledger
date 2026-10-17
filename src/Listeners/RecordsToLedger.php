<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Phoenix1331\AiHeadroomLedger\Events\LedgerEntryCompleted;
use Phoenix1331\AiHeadroomLedger\Events\LedgerEntryRecorded;
use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Recording\EntryRecorder;
use Throwable;

/**
 * Queued by default so recording never slows a request. With the "sync"
 * connection (configured, or the app's default) it runs inline, and any
 * failure is reported instead of thrown so the ledger can never break the
 * AI request that triggered it.
 *
 * @template TEvent of object
 */
abstract class RecordsToLedger implements ShouldQueue
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        protected readonly EntryRecorder $recorder,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param  TEvent  $event
     */
    abstract protected function record(object $event): void;

    /**
     * @param  TEvent  $event
     */
    abstract protected function requestId(object $event): string;

    /**
     * Whether this event means the provider's usage is now recorded.
     */
    protected function completes(): bool
    {
        return false;
    }

    /**
     * @param  TEvent  $event
     */
    public function handle(object $event): void
    {
        if (! config()->boolean('headroom-ledger.enabled', true)) {
            return;
        }

        try {
            $this->record($event);
        } catch (Throwable $e) {
            if (! $this->runsInline()) {
                throw $e;
            }

            report($e);

            return;
        }

        $this->announce($this->requestId($event));
    }

    /*
     * Laravel calls viaConnection() and viaQueue() on an instance built without
     * its constructor, so they read config directly rather than injected services.
     */

    public function viaConnection(): ?string
    {
        $connection = config('headroom-ledger.queue.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function viaQueue(): ?string
    {
        $queue = config('headroom-ledger.queue.name');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }

    private function runsInline(): bool
    {
        $connection = $this->viaConnection() ?? config('queue.default');

        return is_string($connection) && config('queue.connections.'.$connection.'.driver') === 'sync';
    }

    /**
     * Fire the public events, reading the row back only when someone is listening.
     */
    private function announce(string $requestId): void
    {
        $completes = $this->completes();

        if (! $this->events->hasListeners(LedgerEntryRecorded::class)
            && ! ($completes && $this->events->hasListeners(LedgerEntryCompleted::class))) {
            return;
        }

        $entry = Entry::query()->find($requestId, ['request_id', 'invocation_id', 'outcome']);

        if ($entry === null) {
            return;
        }

        $this->events->dispatch(new LedgerEntryRecorded($entry->request_id, $entry->invocation_id, $entry->outcome));

        if ($completes) {
            $this->events->dispatch(new LedgerEntryCompleted($entry->request_id, $entry->invocation_id, $entry->outcome));
        }
    }
}
