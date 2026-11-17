<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Budget;

use App\Entity\BudgetLine;
use App\Message\Query\Budget\IssueBudgetLines;
use App\Message\Query\Budget\ListIssueBudgetLines;
use App\Repository\BudgetLineRepository;
use App\Security\AccessControl;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The page of an issue follows the rule of the budgets pages
 * (`AccessControl::canReadBudgets()`): its lines are read by whoever
 * reads the budgets of the project, and sees the issue; the quantity of
 * a line to estimate, by who `canSeeQuantity()` says.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ListIssueBudgetLinesHandler
{
    public function __construct(
        private readonly BudgetLineRepository $lines,
        private readonly AccessControl $access,
    ) {
    }

    public function __invoke(ListIssueBudgetLines $query): IssueBudgetLines
    {
        if (!$this->access->canReadBudgets($query->viewer, $query->issue->getProject()) || !$this->access->canSee($query->viewer, $query->issue)) {
            return new IssueBudgetLines();
        }
        $lines = $this->lines->findByIssue($query->issue);
        $drafts = array_filter($lines, fn (BudgetLine $line): bool => !$this->access->canSeeQuantity($query->viewer, $line));

        return new IssueBudgetLines($lines, array_values(array_map(static fn (BudgetLine $line): int => (int) $line->getId(), $drafts)));
    }
}
