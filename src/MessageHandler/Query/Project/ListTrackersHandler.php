<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Project;

use App\Entity\Tracker;
use App\Message\Query\Project\ListTrackers;
use App\Repository\EntityList;
use App\Security\Visibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The trackers of the project in display order, the private ones
 * restricted by Security/Visibility as the issue lists restrict them.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ListTrackersHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Visibility $visibility,
    ) {
    }

    /**
     * @return list<Tracker>
     */
    public function __invoke(ListTrackers $query): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Tracker::class, 't')
            ->where('t.project = :project')
            ->setParameter('project', $query->project)
            ->orderBy('t.position')
            ->addOrderBy('t.id');
        $this->visibility->restrictTrackers($queryBuilder, $query->viewer);
        if (!$query->inactive) {
            $queryBuilder->andWhere('t.active = true');
        }

        return EntityList::of($queryBuilder->getQuery(), Tracker::class);
    }
}
