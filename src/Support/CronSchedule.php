<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Support;

use Cron\CronExpression;
use InvalidArgumentException;

/**
 * A configured schedule: a cron expression, or false/null/'' when the app
 * schedules the command itself.
 *
 * @internal
 */
final class CronSchedule
{
    public static function from(string $key, string $command): ?string
    {
        $expression = config($key);

        if ($expression === false || $expression === null || $expression === '') {
            return null;
        }

        if (! is_string($expression) || ! CronExpression::isValidExpression($expression)) {
            throw new InvalidArgumentException(
                "{$key} must be a cron expression such as \"15 0 * * *\", or false to schedule {$command} yourself."
            );
        }

        return $expression;
    }
}
