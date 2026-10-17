<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Phoenix1331\AiHeadroomLedger\Facades\Ledger;
use Phoenix1331\AiHeadroomLedger\LedgerManager;
use Phoenix1331\AiHeadroomLedger\Query\GroupedQuery;
use Phoenix1331\AiHeadroomLedger\Query\LedgerQuery;

/*
 * The query API is the only supported way to read the ledger, so the schema
 * can change without breaking consumers. Nothing a consumer can reach may be,
 * or hand out, an Eloquent model, builder or collection.
 */

/**
 * Every class a consumer can call or receive.
 *
 * @return list<class-string>
 */
function publicApiClasses(): array
{
    $classes = [LedgerManager::class, LedgerQuery::class, GroupedQuery::class, Ledger::class];

    foreach (['Data', 'Events', 'Enums', 'Exceptions'] as $namespace) {
        foreach (glob(__DIR__."/../../src/{$namespace}/*.php") ?: [] as $file) {
            $classes[] = 'Phoenix1331\\AiHeadroomLedger\\'.$namespace.'\\'.basename($file, '.php');
        }
    }

    return $classes;
}

/**
 * Class names in a native type, through unions, intersections and nullables.
 *
 * @return list<string>
 */
function typeNames(?ReflectionType $type): array
{
    return match (true) {
        $type instanceof ReflectionNamedType => [$type->getName()],
        $type instanceof ReflectionUnionType, $type instanceof ReflectionIntersectionType => array_merge(...array_map(typeNames(...), $type->getTypes())),
        default => [],
    };
}

function isEloquent(string $type): bool
{
    return str_starts_with($type, 'Phoenix1331\\AiHeadroomLedger\\Models\\')
        || (class_exists($type) && (is_a($type, Model::class, true) || is_a($type, Builder::class, true) || is_a($type, EloquentCollection::class, true)));
}

arch('value objects, events, enums and exceptions never touch the models')
    ->expect(['Phoenix1331\AiHeadroomLedger\Data', 'Phoenix1331\AiHeadroomLedger\Events', 'Phoenix1331\AiHeadroomLedger\Enums', 'Phoenix1331\AiHeadroomLedger\Exceptions'])
    ->not->toUse(['Phoenix1331\AiHeadroomLedger\Models', 'Illuminate\Database\Eloquent']);

arch('events are final and readonly')
    ->expect('Phoenix1331\AiHeadroomLedger\Events')
    ->classes()
    ->toBeFinal()
    ->toBeReadonly();

it('lists the public API classes it checks', function (): void {
    expect(publicApiClasses())->toContain(LedgerQuery::class, 'Phoenix1331\\AiHeadroomLedger\\Data\\LedgerEntry', 'Phoenix1331\\AiHeadroomLedger\\Events\\LedgerEntryRecorded');
});

it('never returns an Eloquent type from a public method', function (string $class): void {
    $leaks = [];

    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class) {
            continue;
        }

        foreach (typeNames($method->getReturnType()) as $type) {
            if (isEloquent($type)) {
                $leaks[] = "{$method->getName()}(): {$type}";
            }
        }
    }

    expect($leaks)->toBe([]);
})->with(publicApiClasses());

it('never exposes an Eloquent type as a public property', function (string $class): void {
    $leaks = [];

    foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        foreach (typeNames($property->getType()) as $type) {
            if (isEloquent($type)) {
                $leaks[] = "\${$property->getName()}: {$type}";
            }
        }
    }

    expect($leaks)->toBe([]);
})->with(publicApiClasses());

it('keeps the raw query builder private', function (): void {
    expect((new ReflectionMethod(LedgerQuery::class, 'rows'))->isPublic())->toBeFalse();
});

it('documents every manager method on the facade', function (): void {
    $documented = [];
    preg_match_all('/@method static .+? (\w+)\(/', (string) (new ReflectionClass(Ledger::class))->getDocComment(), $documented);

    $public = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            (new ReflectionClass(LedgerManager::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $method): bool => ! $method->isConstructor(),
        ),
    );

    expect($documented[1])->toEqualCanonicalizing($public);
});
