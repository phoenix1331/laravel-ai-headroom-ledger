<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Data;

use ArrayIterator;
use Countable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;

/**
 * Results grouped by model, agent, day, run or tag value, in natural key
 * order (days oldest first, tag 7 before tag 42), with the null group last.
 *
 * @template TValue of Arrayable<string, mixed>
 *
 * @implements IteratorAggregate<int, Group<TValue>>
 */
final readonly class Breakdown implements Countable, IteratorAggregate
{
    /** @var list<Group<TValue>> */
    private array $groups;

    /**
     * @param  iterable<Group<TValue>>  $groups
     */
    public function __construct(iterable $groups = [])
    {
        $list = [];

        foreach ($groups as $group) {
            $list[] = $group;
        }

        usort($list, fn (Group $a, Group $b): int => match (true) {
            $a->key === $b->key => 0,
            $a->key === null => 1,
            $b->key === null => -1,
            default => strnatcmp($a->key, $b->key),
        });

        $this->groups = $list;
    }

    /**
     * @return TValue|null
     */
    public function get(?string $key): mixed
    {
        foreach ($this->groups as $group) {
            if ($group->key === $key) {
                return $group->value;
            }
        }

        return null;
    }

    /**
     * @return list<string|null>
     */
    public function keys(): array
    {
        return array_map(fn (Group $group): ?string => $group->key, $this->groups);
    }

    /**
     * @return list<Group<TValue>>
     */
    public function all(): array
    {
        return $this->groups;
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }

    public function count(): int
    {
        return count($this->groups);
    }

    /**
     * @return Collection<int, Group<TValue>>
     */
    public function collect(): Collection
    {
        return new Collection($this->groups);
    }

    /**
     * @return list<array{key: string|null, value: array<array-key, mixed>}>
     */
    public function toArray(): array
    {
        return array_map(fn (Group $group): array => ['key' => $group->key, 'value' => $group->value->toArray()], $this->groups);
    }

    /**
     * @return Traversable<int, Group<TValue>>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->groups);
    }
}
