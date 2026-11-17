<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Issue;
use App\Entity\IssueLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IssueLink>
 */
class IssueLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IssueLink::class);
    }

    /**
     * The link of this type between the two issues, in this direction or,
     * when asked, in the other one.
     */
    public function findBetween(Issue $source, string $typeId, Issue $target, bool $eitherWay): ?IssueLink
    {
        return $this->findOneBy(['source' => $source, 'target' => $target, 'type' => $typeId])
            ?? ($eitherWay ? $this->findOneBy(['source' => $target, 'target' => $source, 'type' => $typeId]) : null);
    }

    /**
     * @return array<string, int> link type id => links of that type
     */
    public function countByType(): array
    {
        /** @var list<array{typeId: string, links: int|string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.type) AS typeId', 'COUNT(l.id) AS links')
            ->groupBy('l.type')
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(static fn (array $row): array => [$row['typeId'], (int) $row['links']], $rows), 1, 0);
    }
}
