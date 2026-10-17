<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Phoenix1331\AiHeadroomLedger\Data\AccuracyStat;
use Phoenix1331\AiHeadroomLedger\Data\ContextUsage;
use Phoenix1331\AiHeadroomLedger\Data\Money;
use Phoenix1331\AiHeadroomLedger\Data\SpendSummary;
use Phoenix1331\AiHeadroomLedger\Data\TokenTotals;
use Phoenix1331\AiHeadroomLedger\Facades\Ledger;
use Phoenix1331\AiHeadroomLedger\Query\GroupedQuery;
use Phoenix1331\AiHeadroomLedger\Query\LedgerQuery;

/**
 * Spend, tokens, context usage, limits and estimate accuracy for a period,
 * read through the public query API like any other consumer.
 */
final class ReportCommand extends Command
{
    protected $signature = 'headroom-ledger:report
        {--since=7d : Nd for the last N days, or a Y-m-d date (through today)}
        {--by=model : model, provider, agent, day, run, or tag:<key>}
        {--json : Print one JSON document instead of tables}';

    protected $description = 'Report Headroom Ledger spend, tokens, context usage, limits and estimate accuracy';

    public function handle(): int
    {
        $range = $this->range();
        $by = (string) $this->option('by');

        if ($range === null) {
            $this->components->error('--since must be Nd (e.g. 7d) or a Y-m-d date.');

            return self::FAILURE;
        }

        $query = Ledger::query()->between($range[0], $range[1]);
        $grouped = $this->grouped($query, $by);

        if ($grouped === null) {
            $this->components->error('--by must be model, provider, agent, day, run, or tag:<key>.');

            return self::FAILURE;
        }

        $report = $this->collect($query, $grouped);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'from' => $range[0]->toDateString(),
                'to' => $range[1]->toDateString(),
                'by' => $by,
                ...$report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->render($range, $by, $query);

        return self::SUCCESS;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function range(): ?array
    {
        $since = (string) $this->option('since');
        $today = CarbonImmutable::today();

        if (preg_match('/^([1-9][0-9]*)d$/', $since, $days) === 1) {
            return [$today->subDays((int) $days[1] - 1), $today];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $since);

        return $date instanceof CarbonImmutable && $date->toDateString() === $since && $date <= $today ? [$date, $today] : null;
    }

    private function grouped(LedgerQuery $query, string $by): ?GroupedQuery
    {
        if (str_starts_with($by, 'tag:') && strlen($by) > 4) {
            return $query->byTag(substr($by, 4));
        }

        return match ($by) {
            'model' => $query->byModel(),
            'provider' => $query->byProvider(),
            'agent' => $query->byAgent(),
            'day' => $query->byDay(),
            'run' => $query->byRun(),
            default => null,
        };
    }

    /**
     * @return array{totals: array<string, mixed>, groups: list<array<string, mixed>>, accuracy: list<array<string, mixed>>, accuracy_overall: array<string, mixed>}
     */
    private function collect(LedgerQuery $query, GroupedQuery $grouped): array
    {
        $spend = $grouped->spend();
        $tokens = $grouped->tokens();
        $context = $grouped->context();
        $accuracy = $query->accuracy();

        return [
            'totals' => [
                'spend' => $query->spend()->toArray(),
                'tokens' => $query->tokens()->toArray(),
                'context' => $query->context()->toArray(),
            ],
            'groups' => array_map(fn (?string $key): array => [
                'key' => $key,
                'spend' => $spend->get($key)?->toArray(),
                'tokens' => $tokens->get($key)?->toArray(),
                'context' => $context->get($key)?->toArray(),
            ], $spend->keys()),
            'accuracy' => $accuracy->toArray(),
            'accuracy_overall' => $accuracy->overall()->toArray(),
        ];
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $range
     */
    private function render(array $range, string $by, LedgerQuery $query): void
    {
        $grouped = $this->grouped($query, $by) ?? $query->byModel();

        $label = ucfirst(str_starts_with($by, 'tag:') ? substr($by, 4) : $by);
        $this->components->info("Headroom Ledger, {$range[0]->toDateString()} to {$range[1]->toDateString()}");

        $total = $query->spend();

        if ($total->requests === 0) {
            $this->components->warn('No requests in this period.');

            return;
        }

        $spend = $grouped->spend();
        $tokens = $grouped->tokens();
        $context = $grouped->context();

        $this->line('  <options=bold>Spend and tokens</>');
        $this->table(
            [$label, 'Requests', 'Spend', 'Input', 'Output', 'Cached', 'Estimated input'],
            [
                ...array_map(fn (?string $key): array => $this->spendRow($this->name($key, $by), $spend->get($key), $tokens->get($key)), $spend->keys()),
                $this->spendRow('<options=bold>Total</>', $total, $query->tokens()),
            ],
        );

        $this->line('  <options=bold>Context and limits</>');
        $this->table(
            [$label, 'Requests', 'Avg context', 'Max context', 'Warned', 'Blocked', 'Failed'],
            [
                ...array_map(fn (?string $key): array => $this->contextRow($this->name($key, $by), $context->get($key)), $context->keys()),
                $this->contextRow('<options=bold>Total</>', $query->context()),
            ],
        );

        $accuracy = $query->accuracy();

        $this->line('  <options=bold>Estimate accuracy</> (requests that reported usage from the model counted)');
        $this->table(
            ['Model', 'Method', 'Samples', 'Error', 'Bias'],
            $accuracy->isEmpty()
                ? [['No requests reported usage', '', '', '', '']]
                : [
                    ...array_map(fn (AccuracyStat $stat): array => $this->accuracyRow((string) $stat->model, $stat->countMethod->value ?? '-', $stat), $accuracy->all()),
                    $this->accuracyRow('<options=bold>All</>', '', $accuracy->overall()),
                ],
        );

        if ($total->isEstimated()) {
            $this->line('  * includes pre-flight estimates for requests that never reported usage');
        }
    }

    /**
     * @return list<string>
     */
    private function spendRow(string $name, ?SpendSummary $spend, ?TokenTotals $tokens): array
    {
        $spend ??= new SpendSummary;
        $tokens ??= new TokenTotals;
        $amounts = array_map(fn (Money $money): string => self::money($money), $spend->total->all());

        return [
            $name,
            number_format($spend->requests),
            ($amounts === [] ? '-' : implode(' + ', $amounts)).($spend->isEstimated() ? ' *' : ''),
            number_format($tokens->input),
            number_format($tokens->output),
            number_format($tokens->cached()),
            number_format($tokens->estimatedInput),
        ];
    }

    /**
     * @return list<string>
     */
    private function contextRow(string $name, ?ContextUsage $context): array
    {
        $context ??= new ContextUsage;
        $average = $context->averagePercentUsed();

        return [
            $name,
            number_format($context->requests),
            $average === null ? '-' : number_format($average, 1).'%',
            $context->samples === 0 ? '-' : number_format($context->maxPercentUsed, 1).'%',
            number_format($context->warned),
            number_format($context->blocked),
            number_format($context->failed),
        ];
    }

    /**
     * @return list<string>
     */
    private function accuracyRow(string $model, string $method, AccuracyStat $stat): array
    {
        $error = $stat->errorPercent();
        $bias = $stat->biasPercent();

        return [
            $model,
            $method,
            number_format($stat->samples),
            $error === null ? '-' : number_format($error, 1).'%',
            $bias === null ? '-' : sprintf('%+.1f%%', $bias),
        ];
    }

    private function name(?string $key, string $by): string
    {
        if ($key === null) {
            return '(none)';
        }

        return $by === 'agent' ? class_basename($key) : $key;
    }

    /**
     * Up to six decimals, so sub-cent costs stay visible, but never fewer than two.
     */
    private static function money(Money $money): string
    {
        [$units, $decimals] = explode('.', number_format($money->amount(), 6, '.', ','));

        return $money->currency.' '.$units.'.'.str_pad(rtrim($decimals, '0'), 2, '0');
    }
}
