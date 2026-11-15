<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Membership;

use App\Message\Command\Membership\SetReferent;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\MembershipRepository;
use App\Security\Permission;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class SetReferentHandler
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly ProjectActor $actor,
    ) {
    }

    public function __invoke(SetReferent $command): void
    {
        $membership = $this->memberships->find($command->membershipId) ?? throw MembershipException::notFound('Membership', $command->membershipId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::ProjectManageMembers, $membership->getProject());

        if (!$command->referent) {
            $membership->withdrawReferent();

            return;
        }

        $scope = $membership->getProject() ?? $membership->getWorkspace() ?? throw new \LogicException('A membership is on a project or on a workspace.');
        $this->memberships->findReferent($scope, $membership->getRole())?->withdrawReferent();
        $membership->designateReferent();
    }
}
