<?php

declare(strict_types=1);

namespace App\Message\Command\Import;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creates the import of an archive waiting in the imports directory into
 * an existing project. It waits for its analysis, which StartImport
 * launches. Who creates it is kept. Handled by CreateImportHandler, which
 * returns the Import.
 */
final readonly class CreateImport
{
    public function __construct(
        public int $createdById,
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public string $file,
        public int $projectId,
    ) {
    }
}
