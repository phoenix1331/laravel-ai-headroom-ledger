<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Ledger\LedgerFormat;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Phoenix1331\AiHeadroomLedger\Data\AccuracyStats;
use Phoenix1331\AiHeadroomLedger\Data\ContextUsage;
use Phoenix1331\AiHeadroomLedger\Data\LedgerEntries;
use Phoenix1331\AiHeadroomLedger\Data\LedgerEntry;
use Phoenix1331\AiHeadroomLedger\Data\LedgerPage;
use Phoenix1331\AiHeadroomLedger\Data\RunSummary;
use Phoenix1331\AiHeadroomLedger\Data\SpendSummary;
use Phoenix1331\AiHeadroomLedger\Data\TokenTotals;
use Phoenix1331\AiHeadroomLedger\Enums\EntrySort;
use Phoenix1331\AiHeadroomLedger\Facades\Ledger;
use Phoenix1331\AiHeadroomLedger\Query\GroupedQuery;
use Phoenix1331\AiHeadroomLedger\Query\LedgerQuery;

/**
 * Headroom Ledger dashboard. Reads the ledger only through the public query API (the Ledger facade and its
 * value objects), never its tables or models, so it keeps working when the schema changes.
 */
#[Layout('layouts.app')]
#[Title('AI usage ledger')]
class LedgerDashboard extends Component
{
    public const array RANGES = [1 => 'Today', 7 => '7 days', 14 => '14 days', 30 => '30 days'];

    /**
     * Breakdown dimensions. Add a "tag:<key>" entry for each allowlisted tag you want to group by.
     */
    public const array GROUPINGS = [
        'model' => 'Model',
        'provider' => 'Provider',
        'agent' => 'Agent',
        'tag:channel' => 'Channel',
    ];

    /**
     * The allowlisted tag the filter menu uses, such as tenant_id or channel. Its values are read from the ledger.
     */
    public const string FILTER_TAG = 'channel';

    /**
     * Rows per page of recent requests.
     */
    public const int PER_PAGE = 10;

    #[Url]
    public int $days = 7;

    /**
     * The FILTER_TAG value to filter on. Empty for all traffic.
     */
    #[Url]
    public string $channel = '';

    #[Url]
    public string $by = 'model';

    /**
     * Recent requests: page number, sort column (an EntrySort value) and direction.
     */
    #[Url]
    public int $page = 1;

    #[Url]
    public string $sort = 'counted_at';

    #[Url]
    public bool $desc = true;

    /**
     * The agent run (invocation ID) open in the detail panel.
     */
    #[Url]
    public ?string $run = null;

    public function mount(): void
    {
        $this->days = array_key_exists($this->days, self::RANGES) ? $this->days : 7;
        $this->by = array_key_exists($this->by, self::GROUPINGS) ? $this->by : 'model';
        $this->channel = in_array($this->channel, $this->channels(), true) ? $this->channel : '';
        $this->sort = EntrySort::tryFrom($this->sort) === null ? 'counted_at' : $this->sort;
        $this->page = max(1, $this->page);
    }

    public function updated(string $property): void
    {
        if ($property === 'days' && ! array_key_exists($this->days, self::RANGES)) {
            $this->days = 7;
        }

        if ($property === 'by' && ! array_key_exists($this->by, self::GROUPINGS)) {
            $this->by = 'model';
        }

        if ($property === 'channel' && ! in_array($this->channel, $this->channels(), true)) {
            $this->channel = '';
        }

        // A new range or filter changes what is on each page, so start again from the first.
        if (in_array($property, ['days', 'channel'], true)) {
            $this->page = 1;
        }
    }

    /**
     * Sort recent requests by a column; selecting the current column flips the direction.
     */
    public function sortBy(string $sort): void
    {
        if (EntrySort::tryFrom($sort) === null) {
            return;
        }

        $this->desc = $this->sort === $sort ? ! $this->desc : true;
        $this->sort = $sort;
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function openRun(string $invocationId): void
    {
        $this->run = $invocationId;
    }

    public function closeRun(): void
    {
        $this->run = null;
    }

    /**
     * FILTER_TAG values seen in the last 30 days, straight from the query API.
     *
     * @return list<string>
     */
    #[Computed]
    public function channels(): array
    {
        $keys = Ledger::query()->lastDays(30)->byTag(self::FILTER_TAG)->context()->keys();

        return array_values(array_filter($keys, fn (?string $key): bool => $key !== null));
    }

    /**
     * Every figure on the page starts from this query, so the filters apply everywhere.
     */
    protected function query(): LedgerQuery
    {
        $query = Ledger::query()->lastDays($this->days);

        if ($this->channel === '') {
            return $query;
        }

        return $query->forTag(self::FILTER_TAG, $this->channel);
    }

    protected function grouped(): GroupedQuery
    {
        $query = $this->query();

        if (str_starts_with($this->by, 'tag:')) {
            return $query->byTag(substr($this->by, 4));
        }

        return match ($this->by) {
            'provider' => $query->byProvider(),
            'agent' => $query->byAgent(),
            default => $query->byModel(),
        };
    }

    /**
     * Charts plot one currency: the one Headroom prices in. Tables show every currency.
     */
    #[Computed]
    public function currency(): string
    {
        return (string) config('ai-headroom.currency', 'USD');
    }

    #[Computed]
    public function spend(): SpendSummary
    {
        return $this->query()->spend();
    }

    #[Computed]
    public function tokens(): TokenTotals
    {
        return $this->query()->tokens();
    }

    #[Computed]
    public function context(): ContextUsage
    {
        return $this->query()->context();
    }

    #[Computed]
    public function accuracy(): AccuracyStats
    {
        return $this->query()->accuracy();
    }

    /**
     * One row per day of the range, empty days included, with requests split by what happened to them.
     *
     * @return list<array{date: string, label: string, completed: int, warned: int, blocked: int, failed: int, unreported: int, requests: int, spend: float}>
     */
    #[Computed]
    public function daily(): array
    {
        $byDay = $this->query()->byDay();
        $tokens = $byDay->tokens();
        $spend = $byDay->spend();
        $rows = [];

        foreach ($byDay->context() as $group) {
            /** @var ContextUsage $context */
            $context = $group->value;
            /** @var TokenTotals|null $dayTokens */
            $dayTokens = $tokens->get($group->key);
            /** @var SpendSummary|null $daySpend */
            $daySpend = $spend->get($group->key);

            // Warned requests were answered, so they are part of the requests that reported usage.
            $answered = $dayTokens->requestsWithUsage ?? 0;
            $completed = max(0, $answered - $context->warned);
            $unreported = max(0, $context->requests - $completed - $context->warned - $context->blocked - $context->failed);

            $rows[] = [
                'date' => (string) $group->key,
                'label' => date('D j M', strtotime((string) $group->key)),
                'completed' => $completed,
                'warned' => $context->warned,
                'blocked' => $context->blocked,
                'failed' => $context->failed,
                'unreported' => $unreported,
                'requests' => $context->requests,
                'spend' => $daySpend?->actual->in($this->currency())?->amount() ?? 0.0,
            ];
        }

        return $rows;
    }

    /**
     * The breakdown table: spend, tokens and context for each group of the chosen dimension.
     *
     * @return list<array{key: ?string, label: string, spend: SpendSummary, tokens: TokenTotals, context: ContextUsage}>
     */
    #[Computed]
    public function breakdown(): array
    {
        $grouped = $this->grouped();
        $spend = $grouped->spend();
        $tokens = $grouped->tokens();
        $rows = [];

        foreach ($grouped->context() as $group) {
            $rows[] = [
                'key' => $group->key,
                'label' => $this->by === 'agent' ? LedgerFormat::agent($group->key) : ($group->key ?? 'None'),
                'spend' => $spend->get($group->key) ?? new SpendSummary,
                'tokens' => $tokens->get($group->key) ?? new TokenTotals,
                'context' => $group->value,
            ];
        }

        return $rows;
    }

    /**
     * Requests that hit a limit, closest to the context window first.
     */
    #[Computed]
    public function stopped(): LedgerEntries
    {
        return $this->query()->blocked()->orderBy(EntrySort::PercentUsed)->entries(8);
    }

    #[Computed]
    public function recent(): LedgerPage
    {
        return $this->query()
            ->orderBy(EntrySort::tryFrom($this->sort) ?? EntrySort::CountedAt, $this->desc)
            ->paginate(self::PER_PAGE, $this->page);
    }

    /**
     * The run through the page's filters, so a channel filter applies to its steps too.
     */
    #[Computed]
    public function selectedRun(): ?RunSummary
    {
        return $this->run === null ? null : $this->query()->run($this->run);
    }

    /**
     * Status colour role for an outcome; always shown with its label, never alone.
     */
    public function outcomeTone(LedgerEntry $entry): string
    {
        return match ($entry->outcome->value) {
            'completed' => 'good',
            'warned' => 'warning',
            'blocked' => 'critical',
            'failed' => 'serious',
            default => 'neutral',
        };
    }

    public function render(): View
    {
        return view('livewire.ledger-dashboard');
    }
}
