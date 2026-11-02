<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Issue;

use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Entity\User;
use App\Message\Query\Issue\SearchIssues;
use App\Repository\Pagination;
use App\Repository\SearchText;
use App\Repository\Visibility;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lists issues sorted status first, then criticality, then latest activity,
 * restricted to what the viewer may see (Repository/Visibility). Two
 * queries per page: the total, then the rows with tracker, project,
 * workspace and assignee loaded.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class SearchIssuesHandler
{
    public const int PER_PAGE = 50;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Visibility $visibility,
    ) {
    }

    /**
     * @return Pagination<Issue>
     */
    public function __invoke(SearchIssues $query): Pagination
    {
        $viewer = $query->viewer;

        $total = (int) $this->filtered($viewer, $query)
            ->select('COUNT(i.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $rows = $this->filtered($viewer, $query)
            ->addSelect('t', 'p', 'w', 'a')
            ->addSelect("CASE i.status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'waiting' THEN 3 ELSE 4 END AS HIDDEN statusOrder")
            ->addSelect("CASE i.criticality WHEN 'blocking' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END AS HIDDEN criticalityOrder")
            ->orderBy('statusOrder')
            ->addOrderBy('criticalityOrder')
            ->addOrderBy('i.updatedAt', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setFirstResult(($query->page - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE)
            ->getQuery()
            ->getResult();
        if (!\is_array($rows)) {
            throw new \LogicException('An object hydration query returns a list.');
        }
        $issues = array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof Issue));

        return new Pagination($issues, $total, $query->page, self::PER_PAGE);
    }

    private function filtered(User $viewer, SearchIssues $query): QueryBuilder
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Issue::class, 'i')
            ->join('i.tracker', 't')
            ->join('i.project', 'p')
            ->join('p.workspace', 'w')
            ->leftJoin('i.assignee', 'a');

        $this->visibility->restrictIssues($queryBuilder, $viewer);

        if (null !== $query->projectId) {
            $queryBuilder->andWhere('p.id = :projectId')->setParameter('projectId', $query->projectId);
        }
        if (null !== $query->workspaceId) {
            $queryBuilder->andWhere('w.id = :workspaceId')->setParameter('workspaceId', $query->workspaceId);
        }
        if (null !== $query->assigneeId) {
            $queryBuilder->andWhere('a.id = :assigneeId')->setParameter('assigneeId', $query->assigneeId);
        }
        if (null !== $query->authorId) {
            $queryBuilder->andWhere('IDENTITY(i.author) = :authorId')->setParameter('authorId', $query->authorId);
        }

        if ([] !== $query->status) {
            $queryBuilder->andWhere('i.status IN (:status)')->setParameter('status', $query->status);
        }
        // Closed issues stay out unless asked for, by the flag or by status.
        if (!$query->closed && !\in_array(PlaceStatus::Closed, $query->status, true)) {
            $queryBuilder->andWhere('i.status != :closedStatus')->setParameter('closedStatus', PlaceStatus::Closed);
        }
        if ([] !== $query->project) {
            $queryBuilder->andWhere('p.id IN (:project)')->setParameter('project', $query->project);
        }
        if ([] !== $query->tracker) {
            $queryBuilder->andWhere('t.id IN (:tracker)')->setParameter('tracker', $query->tracker);
        }
        if ([] !== $query->criticality) {
            $queryBuilder->andWhere('i.criticality IN (:criticality)')->setParameter('criticality', $query->criticality);
        }
        if ([] !== $query->assignee) {
            $people = array_values(array_filter($query->assignee, static fn (int $id): bool => 0 !== $id));
            $conditions = [];
            if ([] !== $people) {
                $conditions[] = 'a.id IN (:assignee)';
                $queryBuilder->setParameter('assignee', $people);
            }
            if (\in_array(0, $query->assignee, true)) {
                $conditions[] = 'a.id IS NULL';
            }
            $queryBuilder->andWhere($queryBuilder->expr()->orX(...$conditions));
        }

        $text = trim((string) $query->q);
        if (ctype_digit($text)) {
            // A number starts the issue numbers it prefixes: 12 finds 12, 120…
            $queryBuilder
                ->andWhere('LOWER(i.title) LIKE :text OR i.key LIKE :number')
                ->setParameter('text', '%'.$text.'%')
                ->setParameter('number', '%-'.$text.'%');
        } elseif ('' !== $text) {
            $queryBuilder
                ->andWhere("LOWER(i.title) LIKE :text ESCAPE '!' OR i.key = :key")
                ->setParameter('text', SearchText::containing($text))
                ->setParameter('key', mb_strtoupper($text));
        }

        return $queryBuilder;
    }
}
