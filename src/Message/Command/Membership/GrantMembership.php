<?php

declare(strict_types=1);

namespace App\Message\Command\Membership;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Gives a user a role on a workspace or on a project, exactly one of the
 * two; a user who already holds a role there gets the new one instead.
 * Without an actor, the administration grants it; with one, the actor
 * needs `project.manage_members` and grants on a project only.
 * Handled by GrantMembershipHandler.
 */
#[Assert\Expression(
    '(this.workspaceId === null) !== (this.projectId === null)',
    message: 'A membership is granted on a workspace or on a project.',
)]
final readonly class GrantMembership
{
    public function __construct(
        public int $userId,
        public ?int $workspaceId,
        public ?int $projectId,
        #[Assert\NotBlank]
        public string $role,
        public ?int $actorId = null,
    ) {
    }
}
