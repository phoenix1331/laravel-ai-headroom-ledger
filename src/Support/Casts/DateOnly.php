<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Support\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A calendar date stored as "Y-m-d". Laravel's date cast stores a midnight
 * datetime, which SQLite keeps as text that sorts after the plain date, so
 * an inclusive range would miss its last day.
 *
 * @internal
 *
 * @implements CastsAttributes<CarbonImmutable|null, mixed>
 */
final class DateOnly implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return is_string($value) ? CarbonImmutable::parse(substr($value, 0, 10))->startOfDay() : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value)) {
            return CarbonImmutable::parse($value)->toDateString();
        }

        throw new InvalidArgumentException('A date must be a date string or a DateTimeInterface.');
    }
}
