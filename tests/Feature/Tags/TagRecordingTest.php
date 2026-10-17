<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Exceptions;
use Phoenix1331\AiHeadroomLedger\Models\Tag;

/**
 * @return array<string, string>
 */
function storedTags(string $requestId = '01JREQUEST000000000000000A'): array
{
    return Tag::query()->where('request_id', $requestId)->orderBy('key')->pluck('value', 'key')->all();
}

$context = ['tenant_id' => 42, 'user_id' => 7, 'plan' => 'pro', 'email' => 'someone@example.com'];

it('stores no tags by default', function () use ($context): void {
    event(tokensCounted(['context' => $context]));
    event(tokensUsed(['context' => $context]));

    expect(Tag::query()->count())->toBe(0);
});

it('stores only allowlisted keys', function () use ($context): void {
    config(['headroom-ledger.tags.allowed' => ['tenant_id', 'plan']]);

    event(tokensCounted(['context' => $context]));

    expect(storedTags())->toBe(['plan' => 'pro', 'tenant_id' => '42']);
});

it('never stores a key outside the allowlist, from any event', function () use ($context): void {
    config(['headroom-ledger.tags.allowed' => ['tenant_id']]);

    event(tokensCounted(['context' => $context]));
    event(tokensUsed(['context' => $context]));
    event(requestBlocked(['requestId' => 'blocked', 'context' => $context]));

    expect(Tag::query()->pluck('key')->unique()->all())->toBe(['tenant_id'])
        ->and(Tag::query()->where('value', 'someone@example.com')->exists())->toBeFalse();
});

it('records tags from usage that arrives before the count', function (): void {
    config(['headroom-ledger.tags.allowed' => ['tenant_id']]);

    event(tokensUsed(['context' => ['tenant_id' => 42]]));

    expect(storedTags())->toBe(['tenant_id' => '42']);
});

it('records tags carried by a block', function (): void {
    config(['headroom-ledger.tags.allowed' => ['tenant_id']]);

    event(requestBlocked(['context' => ['tenant_id' => 42]]));

    expect(storedTags())->toBe(['tenant_id' => '42']);
});

it('stores each tag once when several events carry it', function (): void {
    config(['headroom-ledger.tags.allowed' => ['tenant_id']]);

    event(tokensCounted(['context' => ['tenant_id' => 42]]));
    event(tokensUsed(['context' => ['tenant_id' => 42]]));
    event(requestBlocked(['context' => ['tenant_id' => 42]]));

    expect(Tag::query()->count())->toBe(1);
});

it('keeps the latest value when a tag changes', function (): void {
    config(['headroom-ledger.tags.allowed' => ['plan']]);

    event(tokensCounted(['context' => ['plan' => 'trial']]));
    event(tokensUsed(['context' => ['plan' => 'pro']]));

    expect(storedTags())->toBe(['plan' => 'pro']);
});

it('stores scalar values as strings', function (): void {
    config(['headroom-ledger.tags.allowed' => ['tenant_id', 'ratio', 'beta', 'trial', 'region']]);

    event(tokensCounted(['context' => ['tenant_id' => 42, 'ratio' => 1.5, 'beta' => true, 'trial' => false, 'region' => 'eu']]));

    expect(storedTags())->toBe(['beta' => '1', 'ratio' => '1.5', 'region' => 'eu', 'tenant_id' => '42', 'trial' => '0']);
});

it('keeps tags separate per request', function (): void {
    config(['headroom-ledger.tags.allowed' => ['tenant_id']]);

    event(tokensCounted(['requestId' => 'a', 'context' => ['tenant_id' => 1]]));
    event(tokensCounted(['requestId' => 'b', 'context' => ['tenant_id' => 2]]));

    expect(storedTags('a'))->toBe(['tenant_id' => '1'])
        ->and(storedTags('b'))->toBe(['tenant_id' => '2']);
});

it('skips and reports a value too long to store, rather than truncating it', function (): void {
    Exceptions::fake();
    config(['headroom-ledger.tags.allowed' => ['tenant_id', 'note']]);

    event(tokensCounted(['context' => ['tenant_id' => 42, 'note' => str_repeat('x', 192)]]));

    expect(storedTags())->toBe(['tenant_id' => '42']);
    Exceptions::assertReported(fn (InvalidArgumentException $e): bool => str_contains($e->getMessage(), '"note" tag'));
});

it('stores a value at the column limit, counting characters not bytes', function (): void {
    config(['headroom-ledger.tags.allowed' => ['city']]);

    event(tokensCounted(['context' => ['city' => str_repeat('é', 191)]]));

    expect(storedTags())->toBe(['city' => str_repeat('é', 191)]);
});

it('ignores allowlist entries that cannot be tag keys', function (mixed $allowed): void {
    config(['headroom-ledger.tags.allowed' => $allowed]);

    event(tokensCounted(['context' => ['tenant_id' => 42, str_repeat('k', 65) => 'v']]));

    expect(Tag::query()->count())->toBe(0);
})->with([
    'not an array' => ['tenant_id'],
    'null' => [null],
    'empty and non-string keys' => [['', 1, null]],
    'a key longer than the column' => [[str_repeat('k', 65)]],
]);
