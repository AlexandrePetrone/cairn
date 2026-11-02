<?php

declare(strict_types=1);

namespace App\MessageHandler\Command;

use App\Entity\Project;
use App\Repository\UserRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Who acts on a project through a command the administration shares with
 * its manager. Without an actor id, the administration acts, guarded at
 * its door, and nothing is checked. With one, the actor must hold the
 * permission on the project, so a workspace (no project) is never theirs,
 * and an unknown actor is granted nothing.
 */
final class ProjectActor
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AccessControl $access,
    ) {
    }

    public function denyUnlessGranted(?int $actorId, Permission $permission, ?Project $project): void
    {
        if (null === $actorId) {
            return;
        }
        $actor = $this->users->find($actorId);
        if (null === $actor || null === $project || !$this->access->isGranted($actor, $permission, $project)) {
            throw new AccessDeniedException(\sprintf('The actor does not hold "%s" on this project.', $permission->value));
        }
    }
}
