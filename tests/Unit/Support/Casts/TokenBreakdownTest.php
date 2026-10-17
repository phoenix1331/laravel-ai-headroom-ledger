<?php

declare(strict_types=1);

use Phoenix1331\AiHeadroomLedger\Models\Entry;
use Phoenix1331\AiHeadroomLedger\Support\Casts\TokenBreakdown;

function breakdownCast(): TokenBreakdown
{
    return new TokenBreakdown;
}

it('stores section token counts as JSON', function (): void {
    expect(breakdownCast()->set(new Entry, 'breakdown', ['instructions' => 10, 'prompt' => 5], []))
        ->toBe('{"instructions":10,"prompt":5}');
});

it('stores null', function (): void {
    expect(breakdownCast()->set(new Entry, 'breakdown', null, []))->toBeNull()
        ->and(breakdownCast()->get(new Entry, 'breakdown', null, []))->toBeNull();
});

it('reads sections back in a fixed order, unknown sections last', function (): void {
    $stored = '{"tools":3,"prompt":5,"web_search":2,"history":4,"instructions":1,"attachments":0}';

    expect(array_keys(breakdownCast()->get(new Entry, 'breakdown', $stored, []) ?? []))
        ->toBe(['instructions', 'history', 'tools', 'attachments', 'prompt', 'web_search']);
});

it('reads invalid JSON as null', function (): void {
    expect(breakdownCast()->get(new Entry, 'breakdown', 'not json', []))->toBeNull();
});

it('rejects anything but section names and whole token counts', function (mixed $value): void {
    breakdownCast()->set(new Entry, 'breakdown', $value, []);
})->with([
    'a string instead of an array' => ['You are a helpful assistant'],
    'text as a value' => [['prompt' => 'Summarise this contract for me']],
    'a float' => [['prompt' => 1.5]],
    'a negative count' => [['prompt' => -1]],
    'a numeric key' => [[0 => 10]],
    'text as a key' => [['Summarise this contract for me' => 10]],
    'a nested array' => [['tools' => ['search' => 10]]],
])->throws(InvalidArgumentException::class, 'token');

it('rejects text read back from the database', function (): void {
    breakdownCast()->get(new Entry, 'breakdown', '{"prompt":"leaked text"}', []);
})->throws(InvalidArgumentException::class);

it('guards the entry model', function (): void {
    $entry = new Entry;
    $entry->breakdown = ['prompt' => 'Summarise this contract for me'];
})->throws(InvalidArgumentException::class);
