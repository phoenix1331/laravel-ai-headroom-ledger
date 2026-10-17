<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Support\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The per-section token breakdown: section names mapped to whole token
 * counts, never text. Read back in a fixed section order, since MySQL's JSON
 * type reorders keys.
 *
 * @internal
 *
 * @implements CastsAttributes<array<string, int>|null, mixed>
 */
final class TokenBreakdown implements CastsAttributes
{
    public const array SECTIONS = ['instructions', 'history', 'tools', 'attachments', 'prompt'];

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, int>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            return null;
        }

        $breakdown = self::validated($decoded);
        $known = array_intersect_key(array_flip(self::SECTIONS), $breakdown);

        return array_replace($known, $breakdown);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('The token breakdown must be an array of section names to token counts.');
        }

        return json_encode(self::validated($value), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<mixed>  $breakdown
     * @return array<string, int>
     */
    private static function validated(array $breakdown): array
    {
        $validated = [];

        foreach ($breakdown as $section => $tokens) {
            if (! is_string($section) || preg_match('/^[a-z][a-z_]{0,31}$/', $section) !== 1 || ! is_int($tokens) || $tokens < 0) {
                throw new InvalidArgumentException('The token breakdown holds section names and whole token counts only.');
            }

            $validated[$section] = $tokens;
        }

        return $validated;
    }
}
