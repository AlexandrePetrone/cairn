<?php

declare(strict_types=1);

namespace App\Message\Command\Release;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creates an open release in a project, its name unique there. The actor
 * needs `release.manage`. Handled by CreateReleaseHandler, which returns
 * the Release.
 */
final readonly class CreateRelease
{
    public function __construct(
        public int $actorId,
        public int $projectId,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 40)]
        public string $name,
        public ?\DateTimeImmutable $plannedOn = null,
        public ?string $description = null,
    ) {
    }
}
