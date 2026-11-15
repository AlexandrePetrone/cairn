<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AutomationRule;
use App\Entity\AutomationRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
        /** @var list<array{rule: int|string, last: string|\DateTimeInterface}> $rows */
        $rows = $this->createQueryBuilder('n')
            ->select('IDENTITY(n.rule) AS rule', 'MAX(n.at) AS last')
            ->andWhere('n.rule IN (:rules)')
            ->setParameter('rules', $rules)
            ->groupBy('n.rule')
            ->getQuery()
            ->getArrayResult();

        $last = [];
        foreach ($rows as $row) {
            $last[(int) $row['rule']] = $row['last'] instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($row['last']) : new \DateTimeImmutable($row['last']);
        }

        return $last;
    }
}
