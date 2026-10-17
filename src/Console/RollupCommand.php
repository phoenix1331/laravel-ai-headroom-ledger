<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Console;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Phoenix1331\AiHeadroomLedger\Rollup\DailyRollupBuilder;

final class RollupCommand extends Command
{
    protected $signature = 'headroom-ledger:rollup
        {--date= : Rebuild one day (Y-m-d)}
        {--from= : Rebuild every day from this one (Y-m-d)}
        {--to= : Last day of the range (Y-m-d); defaults to yesterday}';

    protected $description = 'Build the Headroom Ledger daily rollups (yesterday by default)';

    public function handle(DailyRollupBuilder $builder): int
    {
        try {
            [$from, $to] = $this->days();
        } catch (InvalidFormatException) {
            $this->components->error('Dates must be in Y-m-d format, for example 2026-10-16.');

            return self::FAILURE;
        }

        if ($from > $to) {
            $this->components->error("The range starts ({$from->toDateString()}) after it ends ({$to->toDateString()}).");

            return self::FAILURE;
        }

        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $rows = $builder->build($day);

            $this->components->twoColumnDetail($day->toDateString(), $rows === 1 ? '1 row' : "{$rows} rows");
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function days(): array
    {
        $yesterday = CarbonImmutable::yesterday();
        $date = $this->option('date');

        if (is_string($date) && $date !== '') {
            $day = self::parse($date);

            return [$day, $day];
        }

        $from = $this->option('from');
        $to = $this->option('to');
        $end = is_string($to) && $to !== '' ? self::parse($to) : $yesterday;

        return [is_string($from) && $from !== '' ? self::parse($from) : $end, $end];
    }

    /**
     * Strict Y-m-d: impossible dates such as 2026-02-30 are rejected rather than rolled over.
     */
    private static function parse(string $day): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $day);

        if (! $date instanceof CarbonImmutable || $date->toDateString() !== $day) {
            throw new InvalidFormatException("Invalid date: {$day}");
        }

        return $date;
    }
}
