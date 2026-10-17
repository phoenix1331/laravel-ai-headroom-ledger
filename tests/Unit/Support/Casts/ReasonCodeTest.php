<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroom\Enums\LimitType;
use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Support\Casts\ReasonCode;

it('accepts limit types and exception codes', function (mixed $value, ?string $stored): void {
    expect((new ReasonCode)->set(new Entry, 'outcome_reason', $value, []))->toBe($stored);
})->with([
    'null' => [null, null],
    'limit type enum' => [LimitType::Cost, 'max_cost'],
    'limit type value' => ['context_window', 'context_window'],
    'exception class' => ['RuntimeException', 'RuntimeException'],
    'namespaced class' => ['Illuminate\\Http\\Client\\RequestException', 'Illuminate\\Http\\Client\\RequestException'],
    'leading backslash' => ['\\Laravel\\Ai\\Exceptions\\RateLimited', '\\Laravel\\Ai\\Exceptions\\RateLimited'],
    'class with status' => ['Illuminate\\Http\\Client\\RequestException:529', 'Illuminate\\Http\\Client\\RequestException:529'],
]);

it('rejects messages and anything else that could carry text', function (mixed $value): void {
    (new ReasonCode)->set(new Entry, 'outcome_reason', $value, []);
})->with([
    'an exception message' => ['Your prompt "Summarise this contract" is too long'],
    'class then message' => ['RuntimeException: prompt too long'],
    'class with a space' => ['Runtime Exception'],
    'status out of range' => ['RuntimeException:999'],
    'status with text' => ['RuntimeException:429 Too Many Requests'],
    'too long' => [str_repeat('A', 256)],
    'a number' => [429],
    'an array' => [['RuntimeException']],
])->throws(InvalidArgumentException::class, 'never a message');

it('reads stored codes back unchanged', function (): void {
    expect((new ReasonCode)->get(new Entry, 'outcome_reason', 'max_cost', []))->toBe('max_cost')
        ->and((new ReasonCode)->get(new Entry, 'outcome_reason', null, []))->toBeNull();
});

it('guards the entry model', function (): void {
    $entry = new Entry;
    $entry->outcome_reason = 'Request failed: the prompt mentioned a customer';
})->throws(InvalidArgumentException::class);
