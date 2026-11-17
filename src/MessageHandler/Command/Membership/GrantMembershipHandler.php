<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Membership;

use App\Config\ConfigRegistry;
use App\Entity\Membership;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\Message\Command\Membership\GrantMembership;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\MembershipRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The role must exist in the configuration, an internal-only role goes to
 * internal users only, and a role granted on a project must widen the one
 * the user inherits from its workspace: a narrower one would change
 * nothing, the broadest role wins (AccessControl::roleOn). An actor, from
 * a project, grants on that project only (ProjectActor).
 */
#[AsMessageHandler(bus: 'command.bus')]
final class GrantMembershipHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly WorkspaceRepository $workspaces,
        private readonly ProjectRepository $projects,
        private readonly MembershipRepository $memberships,
        private readonly ConfigRegistry $config,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectActor $actor,
    ) {
    }

    public function __invoke(GrantMembership $command): void
    {
        $definition = $this->config->definition();
        $user = $this->users->find($command->userId) ?? throw MembershipException::notFound('User', $command->userId);
        if (!$definition->hasRole($command->role)) {
            throw MembershipException::unknownRole($command->role);
        }
        $role = $definition->role($command->role);
        // The row of the role, which the access points to; what the role says is read from the definition.
        $granted = $this->entityManager->getReference(Role::class, $role->id) ?? throw new \LogicException('An element of the configuration that the definition has exists.');
        if ($role->internalOnly && !$user->isInternal()) {
            throw MembershipException::internalOnlyRole($role->label, $user->getFullName());
        }

        if (null !== $command->projectId) {
            $project = $this->projects->find($command->projectId) ?? throw MembershipException::notFound('Project', $command->projectId);
            $this->actor->denyUnlessGranted($command->actorId, Permission::ProjectManageMembers, $project);
            $this->assertWidensInherited($user, $project, $command->role);
            $membership = $this->memberships->findOneBy(['user' => $user, 'project' => $project]) ?? Membership::onProject($user, $project, $granted);
        } else {
            $this->actor->denyUnlessGranted($command->actorId, Permission::ProjectManageMembers, null);
            $workspaceId = (int) $command->workspaceId;
            $workspace = $this->workspaces->find($workspaceId) ?? throw MembershipException::notFound('Workspace', $workspaceId);
            $membership = $this->memberships->findOneBy(['user' => $user, 'workspace' => $workspace]) ?? Membership::onWorkspace($user, $workspace, $granted);
        }

        $membership->changeRole($granted);
        $this->entityManager->persist($membership);
    }

    private function assertWidensInherited(User $user, Project $project, string $role): void
    {
        $inherited = $this->memberships->findOneBy(['user' => $user, 'workspace' => $project->getWorkspace()]);
        $definition = $this->config->definition();
        if (null === $inherited) {
            return;
        }
        if ($definition->roleRank($role) >= $definition->roleRank($inherited->getRole())) {
            throw MembershipException::notWiderThanInherited($definition->role($role)->label, $definition->role($inherited->getRole())->label);
        }
    }
}
