<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Project;

use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Message\Query\Project\CountProjectIssues;
use App\Message\Query\Project\ProjectIssueCounts;
use App\Security\Visibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Counts the open issues of the project in one query, grouped by
 * tracker, restricted to what the viewer may see (Security/Visibility):
 * each count is the total of the matching list without any filter
 * (SearchIssues), which CountProjectIssuesTest compares.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class CountProjectIssuesHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Visibility $visibility,
    ) {
    }

    public function __invoke(CountProjectIssues $query): ProjectIssueCounts
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('t.id AS tracker', 'COUNT(i.id) AS total')
            ->addSelect('SUM(CASE WHEN IDENTITY(i.assignee) = :me THEN 1 ELSE 0 END) AS assigned')
            ->addSelect('SUM(CASE WHEN IDENTITY(i.author) = :me THEN 1 ELSE 0 END) AS created')
            ->from(Issue::class, 'i')
            ->join('i.tracker', 't')
            ->join('i.project', 'p')
            ->where('p = :project')
            ->andWhere('i.status != :closed')
            ->groupBy('t.id')
            ->setParameter('project', $query->project)
            ->setParameter('me', $query->viewer->getId())
            ->setParameter('closed', PlaceStatus::Closed);
        $this->visibility->restrictIssues($queryBuilder, $query->viewer);

        $all = $assigned = $created = 0;
        $byTracker = [];
        foreach ($queryBuilder->getQuery()->getArrayResult() as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $total = (int) ($row['total'] ?? 0);
            $byTracker[(int) ($row['tracker'] ?? 0)] = $total;
            $all += $total;
            $assigned += (int) ($row['assigned'] ?? 0);
            $created += (int) ($row['created'] ?? 0);
        }

        return new ProjectIssueCounts($all, $assigned, $created, $byTracker);
    }
}
