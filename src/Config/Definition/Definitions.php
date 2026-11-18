<?php

declare(strict_types=1);

namespace App\Config\Definition;

/**
 * What the definition constructors share: a list turned into a map by id.
 */
final class Definitions
{
    /**
     * Indexes a list of definitions by their identifier property, keeping
     * their order. Two elements of one id cannot be held: the one thing a
     * definition refuses, which no table gives it, where a key is unique.
     *
     * @template T of object
     *
     * @param list<T> $items
     *
     * @return array<string, T>
     */
    public static function indexById(array $items, string $path, string $idProperty = 'id'): array
    {
        $indexed = [];
        foreach ($items as $item) {
            $id = $item->$idProperty;
            if (isset($indexed[$id])) {
                throw new InvalidDefinitionException(\sprintf('%s: duplicate identifier "%s".', $path, $id));
            }
            $indexed[$id] = $item;
        }

        return $indexed;
    }
}
