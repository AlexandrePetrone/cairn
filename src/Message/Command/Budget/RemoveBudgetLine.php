<?php

declare(strict_types=1);

namespace App\Message\Command\Budget;

/**
 * Deletes a line of a budget, for good. The actor needs `budget.manage`.
 * Handled by RemoveBudgetLineHandler.
 */
final readonly class RemoveBudgetLine
{
    public function __construct(
        public int $actorId,
        public int $lineId,
    ) {
    }
}
