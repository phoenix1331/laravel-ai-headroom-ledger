@php
    use App\Ledger\LedgerFormat as F;

    $spend = $this->spend;
    $tokens = $this->tokens;
    $context = $this->context;
    $overall = $this->accuracy->overall();
    $daily = $this->daily;
    $maxRequests = max([1, ...array_column($daily, 'requests')]);
    $maxSpend = max([0.000001, ...array_column($daily, 'spend')]);
    $segments = [
        'completed' => ['Completed', 'good'],
        'warned' => ['Warned', 'warning'],
        'blocked' => ['Blocked', 'critical'],
        'failed' => ['Failed', 'serious'],
        'unreported' => ['No usage reported', 'neutral'],
    ];
    $tick = fn (float $max): float => (float) collect([1, 2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000])->first(fn ($s) => $s * 4 >= $max, 10000);
    $requestStep = $tick($maxRequests);
    $requestTop = $requestStep * 4;
    $spendStep = (float) collect([0.0001, 0.0002, 0.0005, 0.001, 0.002, 0.005, 0.01, 0.02, 0.05, 0.1, 0.2, 0.5, 1, 2, 5, 10, 20, 50, 100])->first(fn ($s) => $s * 4 >= $maxSpend, 1000);
    $spendTop = $spendStep * 4;
    // Label every day for a week, then every few days counting back from today, so labels never collide at phone width.
    $labelEvery = count($daily) <= 7 ? 1 : (count($daily) <= 14 ? 3 : 5);
@endphp

<div class="space-y-8">
    {{-- Title and filters: one row, applied to everything below --}}
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">AI usage ledger</h1>
            <p class="mt-1 max-w-2xl text-sm text-muted">
                Every request your agents sent to a model, as recorded by Headroom Ledger: what Headroom estimated before sending, what the provider reported, the cost, and what was warned or stopped.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2 text-sm">
            <div class="seg" role="group" aria-label="Date range">
                @foreach (\App\Livewire\LedgerDashboard::RANGES as $value => $label)
                    <button type="button" wire:click="$set('days', {{ $value }})" @class(['seg-on' => $days === $value]) aria-pressed="{{ $days === $value ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>

            <label class="sr-only" for="channel">{{ Str::headline(\App\Livewire\LedgerDashboard::FILTER_TAG) }}</label>
            <select id="channel" wire:model.live="channel" class="field">
                <option value="">All {{ Str::plural(Str::lower(Str::headline(\App\Livewire\LedgerDashboard::FILTER_TAG))) }}</option>
                @foreach ($this->channels as $option)
                    <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Headline figures --}}
    <section class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-rule bg-rule lg:grid-cols-5" aria-label="Totals">
        <div class="tile">
            <p class="tile-label">Spend reported</p>
            <p class="tile-value">{{ F::spend($spend->actual) }}</p>
            <p class="tile-note">{{ number_format($spend->requestsWithUsage) }} answered {{ Str::plural('request', $spend->requestsWithUsage) }}</p>
        </div>
        <div class="tile">
            <p class="tile-label">Estimate only</p>
            <p class="tile-value">{{ F::spend($spend->estimatedOnly) }}</p>
            <p class="tile-note">{{ number_format($spend->estimateOnlyRequests) }} never reported usage; worst-case estimate</p>
        </div>
        <div class="tile">
            <p class="tile-label">Tokens in / out</p>
            <p class="tile-value">{{ F::tokens($tokens->input) }} <span class="text-muted">/</span> {{ F::tokens($tokens->output) }}</p>
            <p class="tile-note">{{ F::tokens($tokens->cached()) }} cached &middot; {{ F::tokens($tokens->estimatedInput) }} estimated in</p>
        </div>
        <div class="tile">
            <p class="tile-label">Context used</p>
            <p class="tile-value">{{ F::percent($context->averagePercentUsed(), 2) }} <span class="text-sm font-normal text-muted">avg</span></p>
            <p class="tile-note">Max {{ F::percent($context->samples > 0 ? $context->maxPercentUsed : null, 2) }} of the window</p>
        </div>
        <div class="tile col-span-2 lg:col-span-1">
            <p class="tile-label">Estimate error</p>
            <p class="tile-value">{{ F::percent($overall->errorPercent()) }}</p>
            <p class="tile-note">
                @if ($overall->samples > 0)
                    {{ $overall->biasPercent() >= 0 ? 'Under' : 'Over' }}-estimates by {{ F::percent(abs((float) $overall->biasPercent())) }} &middot; {{ number_format($overall->samples) }} samples
                @else
                    No answered requests to compare yet
                @endif
            </p>
        </div>
    </section>

    {{-- Requests per day by outcome, and reported spend per day --}}
    <section class="grid gap-6 lg:grid-cols-5">
        <div class="panel lg:col-span-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="panel-title">Requests per day</h2>
                <p class="text-xs text-muted">{{ number_format($context->requests) }} total &middot; {{ $context->warned }} warned &middot; {{ $context->blocked }} blocked &middot; {{ $context->failed }} failed</p>
            </div>

            <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted" aria-label="Legend">
                @foreach ($segments as [$label, $tone])
                    <li class="flex items-center gap-1.5"><span class="swatch tone-{{ $tone }}"></span>{{ $label }}</li>
                @endforeach
            </ul>

            <div class="chart mt-4" x-data="{ tip: null }">
                <div class="chart-axis" aria-hidden="true">
                    @foreach ([4, 3, 2, 1, 0] as $i)
                        <span style="bottom: {{ $i * 25 }}%">{{ number_format($requestStep * $i) }}</span>
                    @endforeach
                </div>
                <div class="chart-plot">
                    @foreach ([1, 2, 3, 4] as $i)
                        <div class="chart-grid" style="bottom: {{ $i * 25 }}%"></div>
                    @endforeach

                    @foreach ($daily as $day)
                        <div class="chart-col"
                             @mouseenter="tip = {{ Js::from($day) }}" @mouseleave="tip = null"
                             @focus="tip = {{ Js::from($day) }}" @blur="tip = null" tabindex="0"
                             aria-label="{{ $day['label'] }}: {{ $day['requests'] }} requests">
                            <div class="chart-stack" style="height: {{ $day['requests'] / $requestTop * 100 }}%">
                                @foreach (array_reverse($segments, true) as $key => [$label, $tone])
                                    @if ($day[$key] > 0)
                                        <div class="tone-{{ $tone }}" style="flex-grow: {{ $day[$key] }}"></div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    <template x-if="tip">
                        <div class="tooltip" role="status">
                            <p class="font-medium" x-text="tip.label"></p>
                            <dl class="mt-1 grid grid-cols-[auto_auto] gap-x-4 gap-y-0.5">
                                @foreach ($segments as $key => [$label, $tone])
                                    <dt class="flex items-center gap-1.5 text-muted"><span class="swatch tone-{{ $tone }}"></span>{{ $label }}</dt>
                                    <dd class="text-right font-mono" x-text="tip.{{ $key }}"></dd>
                                @endforeach
                            </dl>
                        </div>
                    </template>
                </div>
                <div class="chart-labels" aria-hidden="true">
                    @foreach ($daily as $index => $day)
                        <span>{{ (count($daily) - 1 - $index) % $labelEvery === 0 ? date('j M', strtotime($day['date'])) : '' }}</span>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="panel lg:col-span-2">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="panel-title">Reported spend per day</h2>
                <p class="text-xs text-muted">{{ $this->currency }}, actual cost only</p>
            </div>

            <div class="chart mt-4" x-data="{ tip: null }">
                <div class="chart-axis" aria-hidden="true">
                    @foreach ([4, 3, 2, 1, 0] as $i)
                        <span style="bottom: {{ $i * 25 }}%">{{ rtrim(rtrim(number_format($spendStep * $i, 4), '0'), '.') }}</span>
                    @endforeach
                </div>
                <div class="chart-plot">
                    @foreach ([1, 2, 3, 4] as $i)
                        <div class="chart-grid" style="bottom: {{ $i * 25 }}%"></div>
                    @endforeach

                    @foreach ($daily as $day)
                        <div class="chart-col"
                             @mouseenter="tip = {{ Js::from(['label' => $day['label'], 'spend' => number_format($day['spend'], 6)]) }}" @mouseleave="tip = null"
                             @focus="tip = {{ Js::from(['label' => $day['label'], 'spend' => number_format($day['spend'], 6)]) }}" @blur="tip = null" tabindex="0"
                             aria-label="{{ $day['label'] }}: {{ number_format($day['spend'], 6) }} {{ $this->currency }}">
                            <div class="chart-stack" style="height: {{ $day['spend'] / $spendTop * 100 }}%">
                                @if ($day['spend'] > 0)
                                    <div class="tone-series" style="flex-grow: 1"></div>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    <template x-if="tip">
                        <div class="tooltip" role="status">
                            <p class="font-medium" x-text="tip.label"></p>
                            <p class="mt-1 font-mono"><span x-text="tip.spend"></span> {{ $this->currency }}</p>
                        </div>
                    </template>
                </div>
                <div class="chart-labels" aria-hidden="true">
                    @foreach ($daily as $index => $day)
                        <span>{{ (count($daily) - 1 - $index) % $labelEvery === 0 ? date('j M', strtotime($day['date'])) : '' }}</span>
                    @endforeach
                </div>
            </div>
        </div>

        <details class="panel lg:col-span-5">
            <summary class="text-sm font-medium">Daily figures as a table</summary>
            <div class="mt-3 overflow-x-auto">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Day</th>
                            @foreach ($segments as [$label])
                                <th class="num">{{ $label }}</th>
                            @endforeach
                            <th class="num">Requests</th>
                            <th class="num">Spend ({{ $this->currency }})</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($daily as $day)
                            <tr>
                                <td>{{ $day['label'] }}</td>
                                @foreach (array_keys($segments) as $key)
                                    <td class="num">{{ $day[$key] }}</td>
                                @endforeach
                                <td class="num">{{ $day['requests'] }}</td>
                                <td class="num">{{ number_format($day['spend'], 6) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </section>

    {{-- Breakdown by the chosen dimension --}}
    <section class="panel">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="panel-title">Breakdown</h2>
            <div class="seg text-sm" role="group" aria-label="Group by">
                @foreach (\App\Livewire\LedgerDashboard::GROUPINGS as $value => $label)
                    <button type="button" wire:click="$set('by', '{{ $value }}')" @class(['seg-on' => $by === $value]) aria-pressed="{{ $by === $value ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="data">
                <thead>
                    <tr>
                        <th>{{ \App\Livewire\LedgerDashboard::GROUPINGS[$by] }}</th>
                        <th class="num">Requests</th>
                        <th class="num">Spend</th>
                        <th class="num">Input</th>
                        <th class="num">Output</th>
                        <th class="num">Est. input</th>
                        <th class="num">Avg context</th>
                        <th class="num">Max context</th>
                        <th class="num">Warned</th>
                        <th class="num">Blocked</th>
                        <th class="num">Failed</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->breakdown as $row)
                        <tr wire:key="group-{{ $row['key'] ?? 'none' }}">
                            <td class="whitespace-nowrap font-medium" title="{{ $row['key'] }}">{{ $row['label'] }}</td>
                            <td class="num">{{ number_format($row['context']->requests) }}</td>
                            <td class="num">{{ F::spend($row['spend']->total) }}@if ($row['spend']->isEstimated())<span class="text-threshold" title="Includes estimates for requests that never reported usage">*</span>@endif</td>
                            <td class="num">{{ F::tokens($row['tokens']->input) }}</td>
                            <td class="num">{{ F::tokens($row['tokens']->output) }}</td>
                            <td class="num">{{ F::tokens($row['tokens']->estimatedInput) }}</td>
                            <td class="num">{{ F::percent($row['context']->averagePercentUsed(), 2) }}</td>
                            <td class="num">{{ F::percent($row['context']->samples > 0 ? $row['context']->maxPercentUsed : null, 2) }}</td>
                            <td class="num">{{ $row['context']->warned }}</td>
                            <td class="num">{{ $row['context']->blocked }}</td>
                            <td class="num">{{ $row['context']->failed }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="py-6 text-center text-muted">No requests in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-muted"><span class="text-threshold">*</span> includes Headroom's pre-flight estimate (input plus worst-case output) for requests that never reported usage, so it overstates.</p>
    </section>

    <section class="grid gap-6 lg:grid-cols-2">
        {{-- Estimate accuracy --}}
        <div class="panel">
            <h2 class="panel-title">Estimate accuracy</h2>
            <p class="mt-1 text-xs text-muted">Headroom's input estimate against the provider's reported input, for requests answered by the model that was counted.</p>
            <div class="mt-4 overflow-x-auto">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Model</th>
                            <th>Method</th>
                            <th class="num">Samples</th>
                            <th class="num">Error</th>
                            <th class="num">Bias</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->accuracy as $stat)
                            <tr>
                                <td class="whitespace-nowrap font-medium">{{ $stat->model ?? '-' }}</td>
                                <td>{{ $stat->countMethod?->value ?? '-' }}</td>
                                <td class="num">{{ number_format($stat->samples) }}</td>
                                <td class="num">{{ F::percent($stat->errorPercent()) }}</td>
                                <td class="num">{{ F::percent($stat->biasPercent()) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-6 text-center text-muted">No answered requests to compare yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-muted">Bias above zero means Headroom under-estimated.</p>
        </div>

        {{-- Stopped and warned --}}
        <div class="panel">
            <h2 class="panel-title">Stopped or warned</h2>
            <p class="mt-1 text-xs text-muted">Requests that hit a Headroom limit, closest to the context window first, with the limit they hit.</p>
            <ul class="mt-4 divide-y divide-rule">
                @forelse ($this->stopped as $entry)
                    <li class="flex items-center justify-between gap-4 py-2.5 text-sm" wire:key="stopped-{{ $entry->requestId }}">
                        <div class="min-w-0">
                            <x-ledger-outcome :entry="$entry" :tone="$this->outcomeTone($entry)" />
                            <span class="ml-2 font-mono text-xs text-muted">{{ $entry->outcomeReason ?? '' }}</span>
                            <p class="mt-0.5 truncate text-xs text-muted">{{ F::agent($entry->agent) }} &middot; {{ $entry->model() ?? '-' }} &middot; {{ $entry->countedAt?->diffForHumans() }}</p>
                        </div>
                        <div class="shrink-0 text-right font-mono text-xs">
                            <p>{{ F::money($entry->estimatedCost) }} est.</p>
                            <p class="text-muted">{{ F::tokens($entry->estimatedInputTokens) }} tokens</p>
                        </div>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-muted">Nothing was stopped or warned in this range.</li>
                @endforelse
            </ul>
        </div>
    </section>

    {{-- Recent requests --}}
    <section class="panel">
        <h2 class="panel-title">Recent requests</h2>
        <p class="mt-1 text-xs text-muted">One row per provider call, newest first. Select a column heading to sort, or a row to see its whole agent run.</p>
        @php($sortable = fn (string $sort, string $label) => ['sort' => $sort, 'label' => $label, 'active' => $this->sort === $sort])
        <div class="mt-4 overflow-x-auto">
            <table class="data">
                <thead>
                    <tr>
                        @foreach ([$sortable('counted_at', 'When'), 'Outcome', 'Agent', 'Model', 'Est. input', $sortable('input_tokens', 'Input'), $sortable('output_tokens', 'Output'), $sortable('percent_used', 'Context'), $sortable('cost', 'Cost'), 'Tags'] as $column)
                            @if (is_string($column))
                                <th @class(['num' => $column === 'Est. input'])>{{ $column }}</th>
                            @else
                                <th @class(['num' => $column['sort'] !== 'counted_at']) aria-sort="{{ $column['active'] ? ($desc ? 'descending' : 'ascending') : 'none' }}">
                                    <button type="button" wire:click="sortBy('{{ $column['sort'] }}')" @class(['sort', 'sort-on' => $column['active']])>
                                        {{ $column['label'] }}<span aria-hidden="true">{{ $column['active'] ? ($desc ? '↓' : '↑') : '' }}</span>
                                    </button>
                                </th>
                            @endif
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->recent->entries as $entry)
                        <tr wire:key="entry-{{ $entry->requestId }}"
                            @if ($entry->invocationId) wire:click="openRun('{{ $entry->invocationId }}')" class="row-link" tabindex="0" @keydown.enter="$wire.openRun('{{ $entry->invocationId }}')" @endif>
                            <td class="whitespace-nowrap" title="{{ $entry->countedAt ?? $entry->usedAt }}">{{ ($entry->countedAt ?? $entry->usedAt)?->format('j M H:i') }}</td>
                            <td><x-ledger-outcome :entry="$entry" :tone="$this->outcomeTone($entry)" /></td>
                            <td title="{{ $entry->agent }}">{{ F::agent($entry->agent) }}</td>
                            <td class="whitespace-nowrap">{{ $entry->model() ?? '-' }}</td>
                            <td class="num">{{ F::tokens($entry->estimatedInputTokens) }}</td>
                            <td class="num">{{ F::tokens($entry->actualInputTokens) }}</td>
                            <td class="num">{{ F::tokens($entry->outputTokens) }}</td>
                            <td class="num">{{ F::percent($entry->percentUsed, 2) }}</td>
                            <td class="num whitespace-nowrap">{{ F::money($entry->cost()) }}@if ($entry->cost()?->estimated)<span class="text-threshold" title="Estimate: no usage was reported">*</span>@endif</td>
                            <td class="whitespace-nowrap font-mono text-xs text-muted">
                                @foreach ($entry->tags as $key => $value)
                                    <span class="tag">{{ $key }}={{ $value }}</span>
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="py-6 text-center text-muted">{{ $this->recent->total === 0 ? 'No requests in this range.' : 'No requests on this page.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @php($recent = $this->recent)
        <nav class="mt-3 flex items-center justify-between gap-4 text-xs text-muted" aria-label="Recent requests pages">
            <p>{{ number_format($recent->total) }} {{ $recent->total === 1 ? 'request' : 'requests' }} &middot; page {{ $recent->page }} of {{ $recent->lastPage() }}</p>
            <div class="flex gap-2">
                <button type="button" wire:click="goToPage({{ $recent->page - 1 }})" class="pager" @disabled($recent->page <= 1)>Previous</button>
                <button type="button" wire:click="goToPage({{ min($recent->page + 1, $recent->lastPage()) }})" class="pager" @disabled(! $recent->hasMorePages())>Next</button>
            </div>
        </nav>
    </section>

    {{-- Agent run detail --}}
    @if ($this->selectedRun)
        @php($run = $this->selectedRun)
        <div class="fixed inset-0 z-20 flex justify-end bg-ink/30" wire:click.self="closeRun" @keydown.escape.window="$wire.closeRun()">
            <aside class="h-full w-full max-w-xl overflow-y-auto border-l border-rule bg-surface p-6 shadow-xl" aria-label="Agent run">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-muted">Agent run</p>
                        <h2 class="mt-1 text-lg font-semibold">{{ F::agent($run->agent) }}</h2>
                        <p class="mt-1 break-all font-mono text-xs text-muted">{{ $run->invocationId }}</p>
                    </div>
                    <button type="button" wire:click="closeRun" class="rounded border border-rule px-2 py-1 text-sm hover:bg-paper">Close</button>
                </div>

                <dl class="mt-6 grid grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-muted">Steps</dt><dd class="font-mono">{{ $run->stepCount() }}</dd></div>
                    <div><dt class="text-muted">Spend</dt><dd class="font-mono">{{ F::spend($run->spend) }}</dd></div>
                    <div><dt class="text-muted">Tokens in / out</dt><dd class="font-mono">{{ number_format($run->tokens->input) }} / {{ number_format($run->tokens->output) }}</dd></div>
                    <div><dt class="text-muted">Duration</dt><dd class="font-mono">{{ $run->startedAt && $run->endedAt ? $run->startedAt->diffInSeconds($run->endedAt).'s' : '-' }}</dd></div>
                </dl>

                <h3 class="mt-8 text-sm font-semibold">Steps</h3>
                <ol class="mt-3 space-y-4">
                    @foreach ($run->steps as $step)
                        <li class="rounded-md border border-rule p-4 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <x-ledger-outcome :entry="$step" :tone="$this->outcomeTone($step)" />
                                <span class="font-mono text-xs text-muted">{{ $step->countedAt?->format('H:i:s') }}</span>
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs">
                                <dt class="text-muted">Counted</dt><dd class="font-mono">{{ $step->providerCounted ?? '-' }} / {{ $step->modelCounted ?? '-' }}</dd>
                                <dt class="text-muted">Answered</dt><dd class="font-mono">{{ $step->providerUsed ?? '-' }} / {{ $step->modelUsed ?? '-' }}</dd>
                                <dt class="text-muted">Count method</dt><dd class="font-mono">{{ $step->countMethod?->value ?? '-' }}</dd>
                                <dt class="text-muted">Estimate / actual in</dt><dd class="font-mono">{{ number_format($step->estimatedInputTokens ?? 0) }} / {{ $step->actualInputTokens !== null ? number_format($step->actualInputTokens) : '-' }}</dd>
                                <dt class="text-muted">Output</dt><dd class="font-mono">{{ $step->outputTokens !== null ? number_format($step->outputTokens) : '-' }}</dd>
                                <dt class="text-muted">Context</dt><dd class="font-mono">{{ F::percent($step->percentUsed, 2) }} of {{ $step->contextWindow ? number_format($step->contextWindow) : '-' }}</dd>
                                <dt class="text-muted">Estimated cost</dt><dd class="font-mono">{{ F::money($step->estimatedCost) }}</dd>
                                <dt class="text-muted">Actual cost</dt><dd class="font-mono">{{ F::money($step->actualCost) }}{{ $step->actualCost?->approximate ? ' (approx.)' : '' }}</dd>
                                @if ($step->outcomeReason)
                                    <dt class="text-muted">Reason</dt><dd class="font-mono">{{ $step->outcomeReason }}</dd>
                                @endif
                            </dl>

                            @if ($step->breakdown)
                                @php($slots = ['instructions' => 0, 'history' => 1, 'tools' => 2, 'attachments' => 3, 'prompt' => 4])
                                <p class="mt-4 text-xs text-muted">Estimated input by section</p>
                                <div class="mt-1.5 flex h-2 gap-0.5 overflow-hidden rounded-full" aria-hidden="true">
                                    @foreach (array_filter($step->breakdown) as $section => $count)
                                        <div class="cat-{{ $slots[$section] ?? 5 }}" style="flex-grow: {{ $count }}"></div>
                                    @endforeach
                                </div>
                                <ul class="mt-2 grid grid-cols-2 gap-x-4 gap-y-0.5 text-xs">
                                    @foreach (array_filter($step->breakdown) as $section => $count)
                                        <li class="flex items-center justify-between gap-2">
                                            <span class="flex items-center gap-1.5 text-muted"><span class="swatch cat-{{ $slots[$section] ?? 5 }}"></span>{{ $section }}</span>
                                            <span class="font-mono">{{ number_format($count) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            <p class="mt-3 break-all font-mono text-[11px] text-muted">{{ $step->requestId }}</p>
                        </li>
                    @endforeach
                </ol>
            </aside>
        </div>
    @endif
</div>
