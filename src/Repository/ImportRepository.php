<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\ImportStatus;
use App\Entity\Import;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Import>
 */
class ImportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Import::class);
    }

    /**
     * @return list<Import>
     */
    public function findAllLatestFirst(): array
    {
        return $this->createQueryBuilder('import')
            ->addSelect('project', 'workspace', 'author')
            ->join('import.project', 'project')
            ->join('project.workspace', 'workspace')
            ->join('import.createdBy', 'author')
            ->orderBy('import.id', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * An import that is not over, into the project or of the archive:
     * one at a time for each.
     */
    public function findUnfinished(?Project $project = null, ?string $file = null): ?Import
    {
        $queryBuilder = $this->createQueryBuilder('import')
            ->andWhere('import.status NOT IN (:finished)')
            ->setParameter('finished', [ImportStatus::Completed->value, ImportStatus::Abandoned->value])
            ->setMaxResults(1);
        if (null !== $project) {
            $queryBuilder->andWhere('import.project = :project')->setParameter('project', $project);
        }
        if (null !== $file) {
            $queryBuilder->andWhere('import.file = :file')->setParameter('file', $file);
        }

        return $queryBuilder->getQuery()->getOneOrNullResult();
    }

    /**
     * The archives an import is on: they are not offered for another one.
     *
     * @return list<string>
     */
    public function filesInUse(): array
    {
        return array_column($this->createQueryBuilder('import')
            ->select('import.file')
            ->andWhere('import.status NOT IN (:finished)')
            ->setParameter('finished', [ImportStatus::Completed->value, ImportStatus::Abandoned->value])
            ->getQuery()
            ->getArrayResult(), 'file');
    }
}
