<?php

namespace App\Scheduling;

use Generator;
use Iterator;
use IteratorIterator;
use Traversable;

/** Preserve each tenant's priority ordering while taking one item per tenant in each round. */
final class FairQueue
{
    /**
     * @template T
     *
     * @param  array<int, iterable<T>>  $tenants
     * @return Generator<int, array{tenant_id: int, item: T}>
     */
    public static function interleave(array $tenants): Generator
    {
        /** @var array<int, Iterator<int, T>> $iterators */
        $iterators = [];
        foreach ($tenants as $tenant => $items) {
            if (! $items instanceof Traversable) {
                $items = new \ArrayIterator($items);
            }
            $iterator = new IteratorIterator($items);
            $iterator->rewind();
            if ($iterator->valid()) {
                $iterators[$tenant] = $iterator;
            }
        }
        while ($iterators !== []) {
            foreach ($iterators as $tenant => $iterator) {
                yield ['tenant_id' => $tenant, 'item' => $iterator->current()];
                $iterator->next();
                if (! $iterator->valid()) {
                    unset($iterators[$tenant]);
                }
            }
        }
    }
}
