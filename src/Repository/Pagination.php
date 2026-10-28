<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * One page of a list, with what is needed to draw the pager. Returned by
 * repositories and query handlers alike.
 *
 * @template T
 */
final readonly class Pagination
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
