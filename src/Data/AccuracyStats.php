<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Data;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Phoenix1331\AiHeadroom\Enums\CountMethod;
use Traversable;

/**
 * Estimate accuracy per model and count method, ordered by model then method.
 *
 * @implements IteratorAggregate<int, AccuracyStat>
 */
final readonly class AccuracyStats implements Countable, IteratorAggregate
{
    /** @var list<AccuracyStat> */
    private array $stats;

    /**
     * @param  iterable<AccuracyStat>  $stats
     */
    public function __construct(iterable $stats = [])
    {
        $list = [];

        foreach ($stats as $stat) {
            $list[] = $stat;
        }

        usort($list, fn (AccuracyStat $a, AccuracyStat $b): int => [(string) $a->model, $a->countMethod->value ?? '']
            <=> [(string) $b->model, $b->countMethod->value ?? '']);

        $this->stats = $list;
    }

    public function for(string $model, ?CountMethod $method = null): ?AccuracyStat
    {
        $matching = array_filter(
            $this->stats,
            fn (AccuracyStat $stat): bool => $stat->model === $model && ($method === null || $stat->countMethod === $method),
        );

        return $matching === [] ? null : self::combine($matching);
    }

    /**
     * Every model and method together.
     */
    public function overall(): AccuracyStat
    {
        return self::combine($this->stats) ?? new AccuracyStat(null, null, 0, 0, 0, 0);
    }

    /**
     * @return list<AccuracyStat>
     */
    public function all(): array
    {
        return $this->stats;
    }

    public function isEmpty(): bool
    {
        return $this->stats === [];
    }

    public function count(): int
    {
        return count($this->stats);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (AccuracyStat $stat): array => $stat->toArray(), $this->stats);
    }

    /**
     * @return Traversable<int, AccuracyStat>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->stats);
    }

    /**
     * @param  array<int, AccuracyStat>  $stats
     */
    private static function combine(array $stats): ?AccuracyStat
    {
        $first = array_shift($stats);

        return $first === null ? null : array_reduce($stats, fn (AccuracyStat $total, AccuracyStat $stat): AccuracyStat => $total->plus($stat), $first);
    }
}
