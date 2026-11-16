<?php

declare(strict_types=1);

namespace App\Message\Query\Budget;

use App\Entity\Project;
use App\Message\Query\Query;

/**
 * The budgets of a project, the oldest first, each with what its lines
 * amount to. The same for whoever reads them: nothing of a budget is
 * hidden from the people allowed on its pages.
 *
 * @implements Query<list<BudgetSummary>>
 */
final readonly class ListBudgets implements Query
{
    public function __construct(
        public Project $project,
    ) {
    }
}
