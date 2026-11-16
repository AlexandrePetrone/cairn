<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Budget;

use App\Entity\BudgetLine;
use App\Message\Query\Budget\ListIssueBudgetLines;
use App\Repository\BudgetLineRepository;
use App\Security\AccessControl;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The page of an issue follows the rule of the budgets pages
 * (`AccessControl::canReadBudgets()`): its lines are read by whoever
 * reads the budgets of the project, and sees the issue.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ListIssueBudgetLinesHandler
{
    public function __construct(
        private readonly BudgetLineRepository $lines,
        private readonly AccessControl $access,
    ) {
    }

    /**
     * @return list<BudgetLine>
     */
    public function __invoke(ListIssueBudgetLines $query): array
    {
        if (!$this->access->canReadBudgets($query->viewer, $query->issue->getProject()) || !$this->access->canSee($query->viewer, $query->issue)) {
            return [];
        }

        return $this->lines->findByIssue($query->issue);
    }
}
