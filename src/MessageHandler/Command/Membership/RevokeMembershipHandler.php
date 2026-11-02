<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Membership;

use App\Message\Command\Membership\RevokeMembership;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\MembershipRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class RevokeMembershipHandler
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectActor $actor,
    ) {
    }

    public function __invoke(RevokeMembership $command): void
    {
        $membership = $this->memberships->find($command->membershipId) ?? throw MembershipException::notFound('Membership', $command->membershipId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::ProjectManageMembers, $membership->getProject());
        $this->entityManager->remove($membership);
    }
}
