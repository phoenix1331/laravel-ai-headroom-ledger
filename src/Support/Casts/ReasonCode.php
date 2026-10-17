<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Support\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Phoenix1331\AiHeadroom\Enums\LimitType;

/**
 * Why a request was warned, blocked or failed, as a code rather than prose:
 * a Headroom limit type, or an exception class with an optional HTTP status
 * ("Illuminate\Http\Client\RequestException:529"). Exception messages are
 * rejected because provider errors can echo prompt content.
 *
 * @internal
 *
 * @implements CastsAttributes<string|null, mixed>
 */
final class ReasonCode implements CastsAttributes
{
    private const string EXCEPTION_PATTERN = '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*(:[1-5][0-9]{2})?$/';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value instanceof LimitType) {
            return $value?->value;
        }

        if (is_string($value) && (LimitType::tryFrom($value) !== null || self::isExceptionCode($value))) {
            return $value;
        }

        throw new InvalidArgumentException('An outcome reason must be a limit type or an exception class with an optional HTTP status, never a message.');
    }

    private static function isExceptionCode(string $value): bool
    {
        return strlen($value) <= 255 && preg_match(self::EXCEPTION_PATTERN, $value) === 1;
    }
}
