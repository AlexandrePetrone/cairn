<?php

declare(strict_types=1);

namespace App\Message\Command\Import;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Writes a release of an import as the version stood in its archive: its
 * planned date, whether it was delivered, and the dates it was created
 * and delivered. Handled by ImportReleaseHandler, which returns the
 * Release.
 */
final readonly class ImportRelease
{
    public function __construct(
        public int $projectId,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 40)]
        public string $name,
        public ?string $description = null,
        public ?\DateTimeImmutable $plannedOn = null,
        public bool $delivered = false,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $deliveredAt = null,
    ) {
    }
}
