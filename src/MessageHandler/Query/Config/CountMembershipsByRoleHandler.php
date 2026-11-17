<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Config;

use App\Entity\Membership;
use App\Message\Query\Config\CountMembershipsByRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class CountMembershipsByRoleHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, int> role id => memberships
     */
    public function __invoke(CountMembershipsByRole $query): array
    {
        /** @var list<array{role: string, memberships: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(m.role) AS role', 'COUNT(m.id) AS memberships')
            ->from(Membership::class, 'm')
            ->groupBy('m.role')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['role']] = (int) $row['memberships'];
        }

        return $counts;
    }
}
