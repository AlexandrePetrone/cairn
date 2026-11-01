<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Membership;

use App\Config\ConfigRegistry;
use App\Entity\Membership;
use App\Entity\Project;
use App\Entity\User;
use App\Message\Command\Membership\GrantMembership;
use App\Repository\MembershipRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The role must exist in the configuration, an internal-only role goes to
 * internal users only, and a role granted on a project must widen the one
 * the user inherits from its workspace: a narrower one would change
 * nothing, the broadest role wins (AccessControl::roleOn).
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
        if ($role->internalOnly && !$user->isInternal()) {
            throw MembershipException::internalOnlyRole($role->label, $user->getFullName());
        }

        if (null !== $command->projectId) {
            $project = $this->projects->find($command->projectId) ?? throw MembershipException::notFound('Project', $command->projectId);
            $this->assertWidensInherited($user, $project, $command->role);
            $membership = $this->memberships->findOneBy(['user' => $user, 'project' => $project]) ?? Membership::onProject($user, $project, $command->role);
        } else {
            $workspaceId = (int) $command->workspaceId;
            $workspace = $this->workspaces->find($workspaceId) ?? throw MembershipException::notFound('Workspace', $workspaceId);
            $membership = $this->memberships->findOneBy(['user' => $user, 'workspace' => $workspace]) ?? Membership::onWorkspace($user, $workspace, $command->role);
        }

        $membership->changeRole($command->role);
        $this->entityManager->persist($membership);
    }

    private function assertWidensInherited(User $user, Project $project, string $role): void
    {
        $inherited = $this->memberships->findOneBy(['user' => $user, 'workspace' => $project->getWorkspace()]);
        $definition = $this->config->definition();
        if (null === $inherited || !$definition->hasRole($inherited->getRole())) {
            return;
        }
        if ($definition->roleRank($role) >= $definition->roleRank($inherited->getRole())) {
            throw MembershipException::notWiderThanInherited($definition->role($role)->label, $definition->role($inherited->getRole())->label);
        }
    }
}
