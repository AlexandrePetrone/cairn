<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Config;

use App\Entity\Issue;
use App\Entity\Tracker;
use App\Message\Query\Config\ShowWorkflowUsage;
use App\Message\Query\Config\WorkflowUsage;
use App\Repository\EntityList;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Two queries: the trackers, then the issues grouped by tracker and place.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ShowWorkflowUsageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ShowWorkflowUsage $query): WorkflowUsage
    {
        $trackers = EntityList::of(
            $this->entityManager->createQueryBuilder()
                ->select('t', 'p', 'w')
                ->from(Tracker::class, 't')
                ->join('t.project', 'p')
                ->join('p.workspace', 'w')
                ->where('t.workflowId = :workflow')
                ->setParameter('workflow', $query->workflowId)
                ->orderBy('w.name')
                ->addOrderBy('p.name')
                ->addOrderBy('t.position')
                ->getQuery(),
            Tracker::class,
        );

        /** @var list<array{tracker: int|string, place: string, issues: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(i.tracker) AS tracker', 'i.place AS place', 'COUNT(i.id) AS issues')
            ->from(Issue::class, 'i')
            ->join('i.tracker', 't')
            ->where('t.workflowId = :workflow')
            ->setParameter('workflow', $query->workflowId)
            ->groupBy('i.tracker', 'i.place')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['tracker']][$row['place']] = (int) $row['issues'];
        }

        return new WorkflowUsage($trackers, $counts);
    }
}
