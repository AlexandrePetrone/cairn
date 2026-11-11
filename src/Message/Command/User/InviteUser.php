<?php

declare(strict_types=1);

namespace App\Message\Command\User;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Sends an account its invitation: a mail with a link, valid a week, to
 * choose its password. For an active account that has none yet, as many
 * times as needed, each link replacing the previous one. Without an
 * actor the administration sends it; with one, a manager sends it from
 * the Access page of their project (`project.manage_members`) to a
 * direct member of that project. Handled by InviteUserHandler.
 */
#[Assert\Expression(
    'this.actorId === null or this.projectId !== null',
    message: 'An actor invites from a project.',
)]
final readonly class InviteUser
{
    public function __construct(
        public int $userId,
        public ?int $actorId = null,
        public ?int $projectId = null,
    ) {
    }
}
