<?php

declare(strict_types=1);

namespace App\Message\Command\Import;

/**
 * Changes how an import will run, between its analysis and its start, in
 * the shapes of ImportMapping: a part left null keeps its choices. Every
 * choice is checked against the archive and the configuration before any
 * is kept. `people` changes the people it names, one by one. When the trackers or the closed issues change, the statuses
 * newly in use get a guessed place.
 */
final readonly class ConfigureImport
{
    /**
     * @param array<array-key, array{target: string, name: string}>|null        $trackers
     * @param array<array-key, array<array-key, array<array-key, string>>>|null $places
     * @param array<array-key, string>|null                                     $fields
     * @param array<array-key, string>|null                                     $priorities
     * @param array<array-key, string|null>|null                                $links
     * @param array<array-key, array{active: bool, role: string}>|null          $people
     */
    public function __construct(
        public int $importId,
        public ?bool $closed = null,
        public ?bool $files = null,
        public ?bool $versions = null,
        public ?array $trackers = null,
        public ?array $places = null,
        public ?array $fields = null,
        public ?array $priorities = null,
        public ?array $links = null,
        public ?array $people = null,
    ) {
    }
}
