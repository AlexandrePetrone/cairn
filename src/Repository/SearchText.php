<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The LIKE pattern of a text typed in a search box, wildcards escaped with
 * `!` (`… LIKE :text ESCAPE '!'`): a backslash would be quoted differently
 * by MySQL, PostgreSQL and SQLite. Lowercase, to compare with LOWER().
 */
final class SearchText
{
    public static function containing(string $text): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($text))).'%';
    }
}
