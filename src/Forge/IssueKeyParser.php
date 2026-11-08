<?php

declare(strict_types=1);

namespace App\Forge;

/**
 * Finds issue keys (`MD-SPT-12`) in plain text: commit messages, merge
 * request titles and descriptions, branch names. Case-insensitive, since
 * branches are written in lowercase by habit (`fix/md-spt-12-export`).
 * Looks around rather than at word boundaries, so that a key stays one
 * inside a branch name (`fix/MD-SPT-12-export`) and does not start inside
 * another word (`XMD-SPT-12`).
 */
final class IssueKeyParser
{
    private const string PATTERN = '/(?<![A-Z0-9])([A-Z]{2,5}-[A-Z]{2,5}-\d+)(?!\d)/i';

    /**
     * The keys found, upper-cased, each once, in order of first appearance.
     *
     * @return list<string>
     */
    public function keys(string ...$texts): array
    {
        $keys = [];
        foreach ($texts as $text) {
            preg_match_all(self::PATTERN, $text, $matches);
            foreach ($matches[1] as $key) {
                $keys[strtoupper($key)] = true;
            }
        }

        return array_keys($keys);
    }
}
