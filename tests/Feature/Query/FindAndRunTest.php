<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Phoenix1331\AiHeadroomLedger\Data\LedgerEntry;
use Phoenix1331\AiHeadroomLedger\Data\RunSummary;
use Phoenix1331\AiHeadroomLedger\Database\Factories\LedgerEntryFactory;
use Phoenix1331\AiHeadroomLedger\Enums\Outcome;
use Phoenix1331\AiHeadroomLedger\Facades\Ledger;
use Phoenix1331\AiHeadroomLedger\Models\Entry;

describe('find', function (): void {
    it('returns null for an unknown request', function (): void {
        expect(Ledger::find('missing'))->toBeNull();
    });

    it('returns a recorded request as a value object', function (): void {
        CarbonImmutable::setTestNow('2026-10-17 09:30:00');
        config(['headroom-ledger.tags.allowed' => ['tenant_id', 'plan']]);
        event(tokensCounted(['context' => ['tenant_id' => 42, 'plan' => 'pro']]));
        event(tokensUsed(['cacheReadTokens' => 800]));

        $entry = Ledger::find('01JREQUEST000000000000000A');

        expect($entry)->toBeInstanceOf(LedgerEntry::class)
            ->requestId->toBe('01JREQUEST000000000000000A')
            ->invocationId->toBe('0199f3a2-0000-7000-8000-000000000001')
            ->agent->toBe('App\\Ai\\Agents\\SupportAgent')
            ->modelCounted->toBe('claude-sonnet-5-5')
            ->modelUsed->toBe('claude-sonnet-5-5')
            ->countMethod->toBe(CountMethod::Estimated)
            ->estimatedInputTokens->toBe(1_200)
            ->breakdown->toBe(['instructions' => 300, 'history' => 600, 'tools' => 100, 'attachments' => 0, 'prompt' => 200])
            ->contextWindow->toBe(200_000)
            ->percentUsed->toBe(0.6)
            ->actualInputTokens->toBe(1_250)
            ->outputTokens->toBe(300)
            ->cacheReadTokens->toBe(800)
            ->streamed->toBeFalse()
            ->outcome->toBe(Outcome::Completed)
            ->outcomeReason->toBeNull()
            ->countedAt->toEqual(CarbonImmutable::parse('2026-10-17 09:30:00'))
            ->usedAt->toEqual(CarbonImmutable::parse('2026-10-17 09:30:05'))
            ->tags->toBe(['plan' => 'pro', 'tenant_id' => '42'])
            ->and($entry?->hasUsage())->toBeTrue()
            ->and($entry?->estimateError())->toBe(50)
            ->and($entry?->estimatedCost)->microUnits->toBe(18_960)->estimated->toBeTrue()
            ->and($entry?->actualCost)->microUnits->toBe(8_250)->estimated->toBeFalse()->currency->toBe('USD')
            ->and($entry?->cost())->toBe($entry?->actualCost)
            ->and($entry?->tokens()->cached())->toBe(800);
    });

    it('falls back to the estimate when usage was never reported', function (): void {
        $row = LedgerEntryFactory::new()->blocked()->create();

        $entry = Ledger::find($row->request_id);

        expect($entry?->hasUsage())->toBeFalse()
            ->and($entry?->actualCost)->toBeNull()
            ->and($entry?->actualInputTokens)->toBeNull()
            ->and($entry?->estimateError())->toBeNull()
            ->and($entry?->cost())->estimated->toBeTrue()->microUnits->toBe($row->estimated_cost)
            ->and($entry?->outcome)->toBe(Outcome::Blocked)
            ->and($entry?->outcomeReason)->toBe('context_window');
    });

    it('reports the model that answered, else the one counted', function (): void {
        $failover = LedgerEntryFactory::new()->forModel('claude-sonnet-5-5')->failover('gpt-5')->create();
        $counted = LedgerEntryFactory::new()->forModel('claude-sonnet-5-5')->counted()->create();

        expect(Ledger::find($failover->request_id))->model()->toBe('gpt-5')->provider()->toBe('openai')
            ->and(Ledger::find($counted->request_id))->model()->toBe('claude-sonnet-5-5')->provider()->toBe('anthropic');
    });

    it('flags an approximate actual cost', function (): void {
        $row = LedgerEntryFactory::new()->approximateCost()->create();

        expect(Ledger::find($row->request_id)?->actualCost?->approximate)->toBeTrue();
    });

    it('has no costs for a row recorded only from a warning', function (): void {
        event(headroomLow());

        expect(Ledger::find('01JREQUEST000000000000000A'))
            ->estimatedCost->toBeNull()
            ->actualCost->toBeNull()
            ->and(Ledger::find('01JREQUEST000000000000000A')?->cost())->toBeNull();
    });
});

describe('run', function (): void {
    it('returns null for an unknown run', function (): void {
        expect(Ledger::run('missing'))->toBeNull();
    });

    it('returns every step in order with totals', function (): void {
        $steps = LedgerEntryFactory::new()->count(3)->inRun('run-1', 'App\\Ai\\Agents\\ResearchAgent')->create();
        LedgerEntryFactory::new()->inRun('other-run')->create();

        $run = Ledger::run('run-1');

        expect($run)->toBeInstanceOf(RunSummary::class)
            ->invocationId->toBe('run-1')
            ->agent->toBe('App\\Ai\\Agents\\ResearchAgent')
            ->and($run?->stepCount())->toBe(3)
            ->and(array_map(fn (LedgerEntry $e): string => $e->requestId, $run?->steps->all() ?? []))->toBe($steps->pluck('request_id')->all())
            ->and($run?->tokens->input)->toBe($steps->sum('actual_input_tokens'))
            ->and($run?->tokens->output)->toBe($steps->sum('output_tokens'))
            ->and($run?->spend->sole()->microUnits)->toBe($steps->sum('actual_cost'))
            ->and($run?->spend->isEstimated())->toBeFalse()
            ->and($run?->startedAt)->toEqual($steps[0]->counted_at)
            ->and($run?->endedAt)->toEqual($steps[2]->used_at);
    });

    it('flags run spend that includes an unanswered step', function (): void {
        LedgerEntryFactory::new()->inRun('run-1')->create();
        LedgerEntryFactory::new()->inRun('run-1')->blocked()->create();

        expect(Ledger::run('run-1')?->spend->isEstimated())->toBeTrue();
    });

    it('orders a step recorded from usage alone by when it was used', function (): void {
        event(tokensUsed(['requestId' => 'b', 'invocationId' => 'run-1', 'at' => '2026-10-17 09:31:00']));
        event(tokensCounted(['requestId' => 'a', 'invocationId' => 'run-1', 'at' => '2026-10-17 09:30:00']));

        expect(array_map(fn (LedgerEntry $e): string => $e->requestId, Ledger::run('run-1')?->steps->all() ?? []))->toBe(['a', 'b']);
    });

    it('takes the agent from the first step that knows it', function (): void {
        event(headroomLow(['requestId' => 'a', 'invocationId' => 'run-1', 'at' => '2026-10-17 09:29:00']));
        event(tokensCounted(['requestId' => 'b', 'invocationId' => 'run-1', 'at' => '2026-10-17 09:30:00']));

        expect(Ledger::run('run-1'))
            ->agent->toBe('App\\Ai\\Agents\\SupportAgent')
            ->startedAt->toEqual(CarbonImmutable::parse('2026-10-17 09:30:00'));
    });

    it('has no times when no step recorded one', function (): void {
        event(headroomLow(['invocationId' => 'run-1']));

        expect(Ledger::run('run-1'))
            ->startedAt->toBeNull()
            ->endedAt->toBeNull()
            ->spend->isEmpty()->toBeTrue();
    });
});

describe('scoped to a query', function (): void {
    beforeEach(function (): void {
        CarbonImmutable::setTestNow('2026-10-17 12:00:00');

        LedgerEntryFactory::new()->count(2)->inRun('run-42', 'App\\Ai\\Agents\\SupportAgent')->withTags(['tenant_id' => 42])->create();
        LedgerEntryFactory::new()->count(2)->inRun('run-7', 'App\\Ai\\Agents\\SupportAgent')->withTags(['tenant_id' => 7])->create();
    });

    it('finds a request only when it matches the filters', function (): void {
        $mine = Entry::query()->where('invocation_id', 'run-42')->value('request_id');
        $theirs = Entry::query()->where('invocation_id', 'run-7')->value('request_id');
        $tenant = Ledger::query()->forTag('tenant_id', 42);

        expect($tenant->find((string) $mine)?->requestId)->toBe($mine)
            ->and($tenant->find((string) $mine)?->tags)->toBe(['tenant_id' => '42'])
            ->and($tenant->find((string) $theirs))->toBeNull()
            ->and($tenant->find('missing'))->toBeNull()
            ->and(Ledger::find((string) $theirs)?->requestId)->toBe($theirs);
    });

    it('returns a run only when its steps match the filters', function (): void {
        $tenant = Ledger::query()->forTag('tenant_id', 42);

        expect($tenant->run('run-42')?->stepCount())->toBe(2)
            ->and($tenant->run('run-7'))->toBeNull()
            ->and(Ledger::run('run-7')?->stepCount())->toBe(2)
            ->and($tenant->run('run-42'))->toEqual(Ledger::run('run-42'));
    });

    it('applies every filter, not only tags', function (): void {
        $id = (string) Entry::query()->where('invocation_id', 'run-42')->value('request_id');

        expect(Ledger::query()->forAgent('App\\Ai\\Agents\\ResearchAgent')->find($id))->toBeNull()
            ->and(Ledger::query()->forAgent('App\\Ai\\Agents\\ResearchAgent')->run('run-42'))->toBeNull()
            ->and(Ledger::query()->forModel('no-such-model')->find($id))->toBeNull()
            ->and(Ledger::query()->withOutcome(Outcome::Blocked)->run('run-42'))->toBeNull()
            ->and(Ledger::query()->between(CarbonImmutable::parse('2020-01-01'), CarbonImmutable::parse('2020-01-02'))->find($id))->toBeNull();
    });

    it('keeps only the steps inside a date range', function (): void {
        LedgerEntryFactory::new()->inRun('overnight')->at(CarbonImmutable::parse('2026-10-16 23:59'))->create(['request_id' => 'before-midnight']);
        LedgerEntryFactory::new()->inRun('overnight')->at(CarbonImmutable::parse('2026-10-17 00:01'))->create(['request_id' => 'after-midnight']);

        $steps = Ledger::query()->lastDays(1)->run('overnight')?->steps->all() ?? [];

        expect(array_map(fn (LedgerEntry $e): string => $e->requestId, $steps))->toBe(['after-midnight'])
            ->and(Ledger::run('overnight')?->stepCount())->toBe(2);
    });
});
