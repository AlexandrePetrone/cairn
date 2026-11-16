<?php

declare(strict_types=1);

namespace App\Message\Query\Budget;

use App\Entity\BudgetLine;

/**
 * A budget line by line, as one viewer may read it: every line counts and
 * shows, but the issue of a line is only named to who sees that issue.
 */
final readonly class BudgetStatement
{
    /**
     * @param list<BudgetLine> $lines  the latest first
     * @param list<int>        $hidden ids of the lines whose issue the viewer does not see
     */
    public function __construct(
        public BudgetSummary $summary,
        public array $lines,
        private array $hidden = [],
    ) {
    }

    /** Whether a line names an issue the viewer does not see. */
    public function hides(BudgetLine $line): bool
    {
        return \in_array($line->getId(), $this->hidden, true);
    }
}
