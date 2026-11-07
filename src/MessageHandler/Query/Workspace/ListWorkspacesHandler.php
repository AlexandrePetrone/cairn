<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Workspace;

use App\Entity\Project;
use App\Entity\Workspace;
use App\Message\Query\Workspace\ListWorkspaces;
use App\Repository\EntityList;
use App\Repository\SearchText;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class ListWorkspacesHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<Workspace>
     */
    public function __invoke(ListWorkspaces $query): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('w')
            ->from(Workspace::class, 'w')
            ->orderBy('w.archived')
            ->addOrderBy('w.name');

        if (null !== $query->archived) {
            $queryBuilder->andWhere('w.archived = :archived')->setParameter('archived', $query->archived);
        }
        if ('' !== trim($query->q)) {
            $projects = $this->entityManager->createQueryBuilder()
                ->select('IDENTITY(p.workspace)')
                ->from(Project::class, 'p')
                ->where("LOWER(p.name) LIKE :text ESCAPE '!'");
            $queryBuilder
                ->andWhere("LOWER(w.name) LIKE :text ESCAPE '!' OR w.key = :key OR w.id IN (".$projects->getDQL().')')
                ->setParameter('text', SearchText::containing($query->q))
                ->setParameter('key', mb_strtoupper(trim($query->q)));
        }

        return EntityList::of($queryBuilder->getQuery(), Workspace::class);
    }
}
