<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Tracker;

use App\Entity\Positioned;

/**
 * Keeps a list people arrange numbered from 0 without a gap: the one
 * place that moves an item among the others and closes ranks when one
 * leaves, for the trackers of a project and those of a project template.
 */
final class ListOrder
{
    /**
     * Puts the item at the position asked, within the bounds of the
     * list, and numbers the whole list again.
     *
     * @param list<Positioned> $list the whole list in its order, the item included or not
     */
    public static function move(Positioned $item, array $list, int $position): void
    {
        $others = self::others($item, $list);
        array_splice($others, min($position, \count($others)), 0, [$item]);
        foreach ($others as $index => $each) {
            $each->moveTo($index);
        }
    }

    /**
     * Numbers the list again without the item that leaves it.
     *
     * @param list<Positioned> $list the whole list in its order
     */
    public static function close(Positioned $leaving, array $list): void
    {
        foreach (self::others($leaving, $list) as $index => $each) {
            $each->moveTo($index);
        }
    }

    /**
     * @param list<Positioned> $list
     *
     * @return list<Positioned>
     */
    private static function others(Positioned $item, array $list): array
    {
        return array_values(array_filter($list, static fn (Positioned $other): bool => $other !== $item));
    }
}
