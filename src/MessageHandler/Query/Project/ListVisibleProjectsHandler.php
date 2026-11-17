<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Project;

use App\Entity\Enum\Criticality;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Entity\Project;
use App\Message\Query\Project\ListVisibleProjects;
use App\Message\Query\Project\ProjectSummary;
use App\Repository\EntityList;
use App\Repository\SearchText;
use App\Security\Visibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Two queries: the visible projects, then their issues counted per
 * project under the same visibility as the issue lists, latest activity
 * first, which orders the recent ones.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ListVisibleProjectsHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Visibility $visibility,
    ) {
    }

    /**
     * @return list<ProjectSummary>
     */
    public function __invoke(ListVisibleProjects $query): array
    {
        $projects = $this->projects($query);
        if ([] === $projects) {
            return [];
        }

        $counts = $this->entityManager->createQueryBuilder()
            ->select('p.id AS project')
            ->addSelect('SUM(CASE WHEN step.status != :closed THEN 1 ELSE 0 END) AS open')
            ->addSelect('SUM(CASE WHEN step.status = :waiting THEN 1 ELSE 0 END) AS waiting')
            ->addSelect('SUM(CASE WHEN step.status != :closed AND i.criticality = :blocking THEN 1 ELSE 0 END) AS blocking')
            ->addSelect('MAX(i.updatedAt) AS HIDDEN lastActivity')
            ->from(Issue::class, 'i')
            ->join('i.tracker', 't')
            ->join('i.project', 'p')
            ->join('i.place', 'step')
            ->where('p.id IN (:projects)')
            ->groupBy('p.id')
            ->orderBy('lastActivity', 'DESC')
            ->setParameter('projects', array_keys($projects))
            ->setParameter('closed', PlaceStatus::Closed)
            ->setParameter('waiting', PlaceStatus::Waiting)
            ->setParameter('blocking', Criticality::Blocking);
        $this->visibility->restrictIssues($counts, $query->viewer);

        $summaries = [];
        foreach ($counts->getQuery()->getArrayResult() as $row) {
            $id = (int) $row['project'];
            $summaries[$id] = new ProjectSummary($projects[$id], (int) $row['open'], (int) $row['waiting'], (int) $row['blocking']);
        }

        if (null !== $query->recent) {
            // Projects without a visible issue come after, in name order.
            $recent = $summaries + array_map(static fn (Project $project): ProjectSummary => new ProjectSummary($project), $projects);

            return \array_slice(array_values($recent), 0, $query->recent);
        }

        return array_values(array_map(static fn (Project $project): ProjectSummary => $summaries[(int) $project->getId()] ?? new ProjectSummary($project), $projects));
    }

    /**
     * @return array<int, Project> project id => project, in display order
     */
    private function projects(ListVisibleProjects $query): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('p', 'w')
            ->addSelect('CASE WHEN p.archived = true OR w.archived = true THEN 1 ELSE 0 END AS HIDDEN archivedOrder')
            ->from(Project::class, 'p')
            ->join('p.workspace', 'w')
            ->orderBy('w.name')
            ->addOrderBy('archivedOrder')
            ->addOrderBy('p.name');
        $this->visibility->restrictProjects($queryBuilder, $query->viewer);

        if (null !== $query->recent) {
            $queryBuilder->andWhere('p.archived = false AND w.archived = false')->orderBy('p.name');
        }
        if ('' !== trim($query->q)) {
            $queryBuilder
                ->andWhere("LOWER(p.name) LIKE :text ESCAPE '!' OR LOWER(w.name) LIKE :text ESCAPE '!' OR p.key = :key OR w.key = :key")
                ->setParameter('text', SearchText::containing($query->q))
                ->setParameter('key', mb_strtoupper(trim($query->q)));
        }

        $projects = [];
        foreach (EntityList::of($queryBuilder->getQuery(), Project::class) as $project) {
            $projects[(int) $project->getId()] = $project;
        }

        return $projects;
    }
}
