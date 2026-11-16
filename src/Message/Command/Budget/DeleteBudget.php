<?php

declare(strict_types=1);

namespace App\Message\Command\Budget;

/**
 * Deletes a budget for good, with every line it holds. Only a closed
 * budget is deleted: closing it is what says the order is over. The actor
 * needs `budget.manage`. Handled by DeleteBudgetHandler.
 */
final readonly class DeleteBudget
{
    public function __construct(
        public int $actorId,
        public int $budgetId,
    ) {
    }
}
