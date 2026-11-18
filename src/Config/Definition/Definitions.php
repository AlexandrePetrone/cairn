<?php

declare(strict_types=1);

namespace App\Config\Definition;

/**
 * Helpers shared by the definition constructors.
 */
final class Definitions
{
    public static function assertId(string $id, string $path): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
            throw new InvalidDefinitionException(\sprintf('%s: "%s" is not a valid identifier (lowercase letters, digits and underscores, starting with a letter).', $path, $id));
        }
    }

    public static function assertLabel(string $label, string $path): void
    {
        if ('' === trim($label)) {
            throw new InvalidDefinitionException(\sprintf('%s: a label is required.', $path));
        }
    }

    /**
     * Indexes a list of definitions by their identifier property, keeping
     * their order.
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

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public static function assertIdList(array $ids, string $path): array
    {
        $seen = [];
        foreach ($ids as $id) {
            if (isset($seen[$id])) {
                throw new InvalidDefinitionException(\sprintf('%s: "%s" is listed twice.', $path, $id));
            }
            $seen[$id] = true;
        }

        return array_values($ids);
    }
}
