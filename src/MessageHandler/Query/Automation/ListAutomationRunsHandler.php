<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Automation;

use App\Entity\AutomationRun;
use App\Message\Query\Automation\ListAutomationRuns;
use App\Repository\EntityList;
use App\Repository\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class ListAutomationRunsHandler
{
    public const int PER_PAGE = 50;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return Pagination<AutomationRun>
     */
    public function __invoke(ListAutomationRuns $query): Pagination
    {
        $total = (int) $this->filtered($query)->select('COUNT(n.id)')->getQuery()->getSingleScalarResult();

        $runs = EntityList::of(
            $this->filtered($query)
                ->addSelect('i', 'r', 'a')
                ->join('n.issue', 'i')
                ->leftJoin('n.rule', 'r')
                ->leftJoin('n.actor', 'a')
                ->orderBy('n.id', 'DESC')
                ->setFirstResult(($query->page - 1) * self::PER_PAGE)
                ->setMaxResults(self::PER_PAGE)
                ->getQuery(),
            AutomationRun::class,
        );

        return new Pagination($runs, $total, $query->page, self::PER_PAGE);
    }

    private function filtered(ListAutomationRuns $query): QueryBuilder
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('n')
            ->from(AutomationRun::class, 'n')
            ->andWhere('n.project = :project')
            ->setParameter('project', $query->project);
        if (null !== $query->ruleId) {
            $queryBuilder->andWhere('IDENTITY(n.rule) = :rule')->setParameter('rule', $query->ruleId);
        }
        if (null !== $query->outcome) {
            $queryBuilder->andWhere('n.outcome = :outcome')->setParameter('outcome', $query->outcome->value);
        }

        return $queryBuilder;
    }
}
