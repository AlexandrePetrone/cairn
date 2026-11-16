<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Budget;

use App\Message\Query\Budget\BudgetStatement;
use App\Message\Query\Budget\BudgetSummary;
use App\Message\Query\Budget\ShowBudget;
use App\Repository\BudgetLineRepository;
use App\Security\AccessControl;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Every line shows and counts, so that the balance is the same for all
 * its readers; `AccessControl::canSee()` only decides whether the issue
 * of a line is named.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ShowBudgetHandler
{
    public function __construct(
        private readonly BudgetLineRepository $lines,
        private readonly AccessControl $access,
    ) {
    }

    public function __invoke(ShowBudget $query): BudgetStatement
    {
        $budget = $query->budget;
        $lines = $this->lines->findByBudget($budget);
        $hidden = [];
        foreach ($lines as $line) {
            $issue = $line->getIssue();
            if (null !== $issue && !$this->access->canSee($query->viewer, $issue)) {
                $hidden[] = (int) $line->getId();
            }
        }
        $totals = $this->lines->totalsByBudget($budget->getProject(), $budget)[(int) $budget->getId()] ?? [];

        return new BudgetStatement(new BudgetSummary($budget, ...$totals), $lines, $hidden);
    }
}
