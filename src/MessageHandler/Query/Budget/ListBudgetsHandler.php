<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Budget;

use App\Entity\Budget;
use App\Message\Query\Budget\BudgetSummary;
use App\Message\Query\Budget\ListBudgets;
use App\Repository\BudgetLineRepository;
use App\Repository\BudgetRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class ListBudgetsHandler
{
    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly BudgetLineRepository $lines,
    ) {
    }

    /**
     * @return list<BudgetSummary>
     */
    public function __invoke(ListBudgets $query): array
    {
        $totals = $this->lines->totalsByBudget($query->project);

        return array_map(
            static fn (Budget $budget): BudgetSummary => new BudgetSummary($budget, ...($totals[(int) $budget->getId()] ?? [])),
            $this->budgets->findByProject($query->project),
        );
    }
}
