<?php

declare(strict_types=1);

namespace App\Message\Query\Budget;

use App\Entity\BudgetLine;
use App\Entity\Issue;
use App\Entity\User;
use App\Message\Query\Query;

/**
 * The lines of the budgets that name an issue, with their budget, the
 * oldest first: what the page of the issue shows of them. Nothing for a
 * viewer who may neither read nor manage the budgets of its project, nor
 * on a project without budgets.
 *
 * @implements Query<list<BudgetLine>>
 */
final readonly class ListIssueBudgetLines implements Query
{
    public function __construct(
        public User $viewer,
        public Issue $issue,
    ) {
    }
}
