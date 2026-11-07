<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Project;

use App\Entity\Project;
use App\Message\Query\Project\ListProjects;
use App\Repository\EntityList;
use App\Repository\SearchText;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Searches the name and key of the projects and the name of their
 * workspace.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ListProjectsHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<Project>
     */
    public function __invoke(ListProjects $query): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('p', 'w')
            ->addSelect('CASE WHEN p.archived = true OR w.archived = true THEN 1 ELSE 0 END AS HIDDEN archivedOrder')
            ->from(Project::class, 'p')
            ->join('p.workspace', 'w')
            ->orderBy('w.name')
            ->addOrderBy('archivedOrder')
            ->addOrderBy('p.name');

        if (null !== $query->workspaceId) {
            $queryBuilder->andWhere('w.id = :workspaceId')->setParameter('workspaceId', $query->workspaceId);
        }
        if ('' !== trim($query->q)) {
            $queryBuilder
                ->andWhere("LOWER(p.name) LIKE :text ESCAPE '!' OR LOWER(w.name) LIKE :text ESCAPE '!' OR p.key = :key OR w.key = :key")
                ->setParameter('text', SearchText::containing($query->q))
                ->setParameter('key', mb_strtoupper(trim($query->q)));
        }
        if ([ListProjects::ACTIVE] === $query->state) {
            $queryBuilder->andWhere('p.archived = false AND w.archived = false');
        } elseif ([ListProjects::ARCHIVED] === $query->state) {
            $queryBuilder->andWhere('p.archived = true OR w.archived = true');
        }

        return EntityList::of($queryBuilder->getQuery(), Project::class);
    }
}
