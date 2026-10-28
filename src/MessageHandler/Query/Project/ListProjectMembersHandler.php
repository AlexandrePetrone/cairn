<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Project;

use App\Config\ConfigRegistry;
use App\Entity\Membership;
use App\Entity\User;
use App\Message\Query\Project\ListProjectMembers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The SQL side of AccessControl::roleOn(): a user is a member when a
 * membership on the project or on its workspace carries a role that still
 * exists in the configuration in force. ListProjectMembersTest compares
 * both on the development dataset.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ListProjectMembersHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigRegistry $config,
    ) {
    }

    /**
     * @return list<User>
     */
    public function __invoke(ListProjectMembers $query): array
    {
        $members = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT u')
            ->from(User::class, 'u')
            ->join(Membership::class, 'm', 'WITH', 'm.user = u')
            ->where('m.project = :project OR m.workspace = :workspace')
            ->andWhere('m.role IN (:roles)')
            ->orderBy('u.lastName')
            ->addOrderBy('u.firstName')
            ->setParameter('project', $query->project)
            ->setParameter('workspace', $query->project->getWorkspace())
            ->setParameter('roles', $this->config->definition()->roleIds())
            ->getQuery()
            ->getResult();
        if (!\is_array($members)) {
            throw new \LogicException('An object hydration query returns a list.');
        }

        return array_values(array_filter($members, static fn (mixed $member): bool => $member instanceof User));
    }
}
