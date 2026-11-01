<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Membership;

use App\Message\Command\Membership\RevokeMembership;
use App\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class RevokeMembershipHandler
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RevokeMembership $command): void
    {
        $membership = $this->memberships->find($command->membershipId) ?? throw MembershipException::notFound('Membership', $command->membershipId);
        $this->entityManager->remove($membership);
    }
}
