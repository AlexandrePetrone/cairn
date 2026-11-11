<?php

declare(strict_types=1);

namespace App\Form;

/**
 * Ids as a query string carries them: text, in a list. What is not a
 * positive integer is dropped, never an error, and an id counts once.
 */
final class QueryIds
{
    /**
     * @param bool $withZero keeps 0, which stands for "none" where a filter has such a choice
     *
     * @return list<int>
     */
    public static function of(mixed $values, bool $withZero = false): array
    {
        $ids = [];
        foreach (\is_array($values) ? $values : [] as $value) {
            if (\is_int($value) || (\is_string($value) && ctype_digit($value))) {
                $id = (int) $value;
                if ($id > 0 || ($withZero && 0 === $id)) {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }
}
