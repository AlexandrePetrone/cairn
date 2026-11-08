<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CodeReference;
use App\Entity\Enum\CodeReferenceKind;
use App\Entity\Enum\Forge;
use App\Entity\Issue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CodeReference>
 */
class CodeReferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CodeReference::class);
    }

    public function findOne(Issue $issue, Forge $forge, string $repositoryPath, CodeReferenceKind $kind, string $externalId): ?CodeReference
    {
        return $this->findOneBy(['issue' => $issue, 'forge' => $forge, 'repositoryPath' => $repositoryPath, 'kind' => $kind, 'externalId' => $externalId]);
    }
}
