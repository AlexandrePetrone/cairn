<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The LIKE pattern of a text typed in a search box, wildcards escaped with
 * `!` (`… LIKE :text ESCAPE '!'`), which needs no quoting of its own in a
 * string of DQL or SQL. Lowercase, to compare with LOWER().
 */
final class SearchText
{
    public static function containing(string $text): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($text))).'%';
    }
}
