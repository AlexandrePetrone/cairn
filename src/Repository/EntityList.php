<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\AbstractQuery;

/**
 * The entities an object-hydration query returns, as a list of the class
 * asked for. Doctrine types getResult() as mixed and PHPStan treats no
 * PHPDoc as certain here, so every read would otherwise repeat the same
 * two checks.
 */
final class EntityList
{
    /**
     * @template T of object
     *
     * @param AbstractQuery<mixed, mixed> $query
     * @param class-string<T>             $class
     *
     * @return list<T>
     */
    public static function of(AbstractQuery $query, string $class): array
    {
        $rows = $query->getResult();
        if (!\is_array($rows)) {
            throw new \LogicException('An object hydration query returns a list.');
        }

        return array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof $class));
    }
}
