<?php

declare(strict_types=1);

namespace App\Message\Command\Import;

/**
 * Writes the links of an imported issue to other imported issues, once
 * all of them are written: from this issue to each of the others. A link
 * already there is left alone; nothing is journaled, nobody is notified.
 * They are made in the name of whoever ran the import.
 */
final readonly class ImportLinks
{
    /**
     * @param list<array{typeId: string, issueId: int}> $links
     */
    public function __construct(
        public int $issueId,
        public array $links,
        public int $createdById,
    ) {
    }
}
