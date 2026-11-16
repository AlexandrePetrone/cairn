<?php

declare(strict_types=1);

namespace App\Message\Query\Budget;

use App\Entity\Budget;

/**
 * A budget with what its lines amount to, in minutes: consumed (the
 * approved lines) and pending (those waiting for the client's approval).
 */
final readonly class BudgetSummary
{
    public function __construct(
        public Budget $budget,
        public int $consumed = 0,
        public int $pending = 0,
        /** How many lines it holds, whatever their status. */
        public int $lines = 0,
    ) {
    }

    /** What is left, below zero when the budget is overspent. */
    public function remaining(): int
    {
        return $this->budget->getVolume() - $this->consumed;
    }
}
