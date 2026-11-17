<?php

declare(strict_types=1);

namespace App\Message\Query\Budget;

use App\Entity\BudgetLine;

/**
 * The lines of the budgets that name an issue, as one viewer may read
 * them: the quantity of a line to estimate is only given to who may read
 * a draft.
 */
final readonly class IssueBudgetLines
{
    /**
     * @param list<BudgetLine> $lines  with their budget, the oldest first
     * @param list<int>        $drafts ids of the lines whose quantity the viewer does not read
     */
    public function __construct(
        public array $lines = [],
        private array $drafts = [],
    ) {
    }

    /** The quantity of a line as the viewer reads it, in minutes: none for a draft kept from them. */
    public function quantityOf(BudgetLine $line): ?int
    {
        return \in_array($line->getId(), $this->drafts, true) ? null : $line->getQuantity();
    }
}
