<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Enum\Criticality;

/**
 * The filters of a kanban, mapped from the query string: the board page
 * is a GET form, and each column receives the values as they are kept
 * here. A value the board does not know is dropped, never an error.
 */
final readonly class BoardFilters
{
    /**
     * @param array<mixed> $criticality
     * @param array<mixed> $assignees   user ids, 0 for unassigned
     * @param array<mixed> $releases    release ids, 0 for no release
     */
    public function __construct(
        public array $criticality = [],
        public array $assignees = [],
        public array $releases = [],
        public string $q = '',
    ) {
    }

    /**
     * @return list<string> Criticality values
     */
    public function criticality(): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): ?string => \is_string($value) ? Criticality::tryFrom($value)?->value : null, $this->criticality),
        )));
    }

    /**
     * @return list<int>
     */
    public function assignees(): array
    {
        return self::ids($this->assignees);
    }

    /**
     * @return list<int>
     */
    public function releases(): array
    {
        return self::ids($this->releases);
    }

    public function isEmpty(): bool
    {
        return [] === $this->criticality() && [] === $this->assignees() && [] === $this->releases() && '' === trim($this->q);
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<int> ids, 0 included
     */
    private static function ids(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (\is_string($value) && ctype_digit($value)) {
                $ids[(int) $value] = (int) $value;
            }
        }

        return array_values($ids);
    }
}
