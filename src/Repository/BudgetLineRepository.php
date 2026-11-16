<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\Enum\BudgetLineStatus;
use App\Entity\Issue;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The lines of the budgets, and the one place that says what they count
 * for: an approved line is consumed, a pending one waits, a line to
 * estimate or refused counts for nothing.
 *
 * @extends ServiceEntityRepository<BudgetLine>
 */
class BudgetLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BudgetLine::class);
    }

    /**
     * The lines of a budget, the latest first, with their issue.
     *
     * @return list<BudgetLine>
     */
    public function findByBudget(Budget $budget): array
    {
        return EntityList::of($this->createQueryBuilder('l')
            ->addSelect('i')
            ->leftJoin('l.issue', 'i')
            ->where('l.budget = :budget')
            ->setParameter('budget', $budget)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->getQuery(), BudgetLine::class);
    }

    /**
     * The lines that name an issue, with their budget, the oldest first.
     *
     * @return list<BudgetLine>
     */
    public function findByIssue(Issue $issue): array
    {
        return EntityList::of($this->createQueryBuilder('l')
            ->addSelect('b')
            ->join('l.budget', 'b')
            ->where('l.issue = :issue')
            ->setParameter('issue', $issue)
            ->orderBy('l.createdAt', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery(), BudgetLine::class);
    }

    /**
     * What the budgets of a project hold, in minutes, and how many lines;
     * `$only` keeps the count to one of them.
     *
     * @return array<int, array{consumed: int, pending: int, lines: int}> by budget id; a budget without a line is left out
     */
    public function totalsByBudget(Project $project, ?Budget $only = null): array
    {
        $query = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.budget) AS budget', 'l.status AS status', 'SUM(l.quantity) AS quantity', 'COUNT(l.id) AS lines')
            ->join('l.budget', 'b')
            ->where('b.project = :project')
            ->setParameter('project', $project)
            ->groupBy('l.budget', 'l.status');
        if (null !== $only) {
            $query->andWhere('l.budget = :only')->setParameter('only', $only);
        }
        /** @var list<array{budget: int|string, status: BudgetLineStatus|string, quantity: int|string|null, lines: int|string}> $rows */
        $rows = $query->getQuery()->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $status = \is_string($row['status']) ? BudgetLineStatus::from($row['status']) : $row['status'];
            $total = $totals[(int) $row['budget']] ?? ['consumed' => 0, 'pending' => 0, 'lines' => 0];
            $total['lines'] += (int) $row['lines'];
            if (BudgetLineStatus::Approved === $status) {
                $total['consumed'] += (int) $row['quantity'];
            } elseif (BudgetLineStatus::Pending === $status) {
                $total['pending'] += (int) $row['quantity'];
            }
            $totals[(int) $row['budget']] = $total;
        }

        return $totals;
    }
}
