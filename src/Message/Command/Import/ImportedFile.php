<?php

declare(strict_types=1);

namespace App\Message\Command\Import;

/**
 * A file of an imported issue or comment, taken out of the archive to
 * `path`, which the import moves into the attachments. Its `id` is what
 * the `{{file:ID}}` tokens of the texts name.
 */
final readonly class ImportedFile
{
    public function __construct(
        public string $id,
        public string $path,
        public string $name,
        public int $uploaderId,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
