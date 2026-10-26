<?php

declare(strict_types=1);

namespace App\Config\Diff;

/**
 * Differences between the applied definition and the next one, one entry
 * per added, removed or changed element.
 */
final readonly class ConfigDiff
{
    /**
     * @param list<DiffEntry> $entries
     */
    public function __construct(
        public array $entries,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->entries;
    }

    /**
     * @return list<string>
     */
    public function describe(): array
    {
        return array_map(strval(...), $this->entries);
    }
}
