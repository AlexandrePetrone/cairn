<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AutomationRule;
use App\Entity\AutomationRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AutomationRun>
 */
class AutomationRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AutomationRun::class);
    }

    /**
     * When each rule last ran, whatever the outcome.
     *
     * @param list<AutomationRule> $rules
     *
     * @return array<int, \DateTimeImmutable> rule id => date of its last execution
     */
    public function findLastRuns(array $rules): array
    {
        if ([] === $rules) {
            return [];
        }
        /** @var list<array{rule: int|string, last: string}> $rows */
        $rows = $this->createQueryBuilder('n')
            ->select('IDENTITY(n.rule) AS rule', 'MAX(n.at) AS last')
            ->andWhere('n.rule IN (:rules)')
            ->setParameter('rules', $rules)
            ->groupBy('n.rule')
            ->getQuery()
            ->getArrayResult();

        // An aggregate comes back as the column holds it: its type knows what it means.
        $connection = $this->getEntityManager()->getConnection();
        $last = [];
        foreach ($rows as $row) {
            $at = $connection->convertToPHPValue($row['last'], Types::DATETIME_IMMUTABLE);
            \assert($at instanceof \DateTimeImmutable);
            $last[(int) $row['rule']] = $at;
        }

        return $last;
    }
}
