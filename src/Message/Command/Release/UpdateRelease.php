<?php

declare(strict_types=1);

namespace App\Message\Command\Release;

use App\Entity\Enum\ReleaseStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes a release, PATCH style: a property left null keeps its value.
 * Its status delivers, abandons or reopens it; the issues it holds stay
 * in it. The actor needs `release.manage`. Handled by UpdateReleaseHandler.
 */
final readonly class UpdateRelease
{
    public function __construct(
        public int $actorId,
        public int $releaseId,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 40)]
        public ?string $name = null,
        public ?\DateTimeImmutable $plannedOn = null,
        /** Removes the planned date; plannedOn is then ignored. */
        public bool $unplanned = false,
        /** An empty string clears it. */
        public ?string $description = null,
        public ?ReleaseStatus $status = null,
    ) {
    }
}
