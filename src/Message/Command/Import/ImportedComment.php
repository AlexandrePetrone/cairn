<?php

declare(strict_types=1);

namespace App\Message\Command\Import;

/**
 * A comment of an imported issue, as written then, and the files that
 * came with it.
 */
final readonly class ImportedComment
{
    /**
     * @param list<ImportedFile> $files
     */
    public function __construct(
        public int $authorId,
        public string $body,
        public bool $private,
        public \DateTimeImmutable $createdAt,
        public array $files = [],
    ) {
    }
}
