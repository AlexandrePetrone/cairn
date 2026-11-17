<?php

declare(strict_types=1);

namespace App\Message\Query\Budget;

use App\Entity\BudgetLine;

/**
 * A budget line by line, as one viewer may read it: every line counts and
 * shows, but the issue of a line is only named to who sees that issue,
 * and the quantity of a line to estimate, which counts for nothing, is
 * only given to who may read a draft.
 */
final readonly class BudgetStatement
{
    /**
     * @param list<BudgetLine> $lines  the latest first
     * @param list<int>        $hidden ids of the lines whose issue the viewer does not see
     * @param list<int>        $drafts ids of the lines whose quantity the viewer does not read
     */
    public function __construct(
        public BudgetSummary $summary,
        public array $lines,
        private array $hidden = [],
        private array $drafts = [],
    ) {
    }

    /** The quantity of a line as the viewer reads it, in minutes: none for a draft kept from them. */
    public function quantityOf(BudgetLine $line): ?int
    {
        return \in_array($line->getId(), $this->drafts, true) ? null : $line->getQuantity();
    }

    /** Whether a line names an issue the viewer does not see. */
    public function hides(BudgetLine $line): bool
    {
        return \in_array($line->getId(), $this->hidden, true);
    }
}
