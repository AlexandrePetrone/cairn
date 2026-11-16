<?php

declare(strict_types=1);

namespace App\Message\Query\Budget;

use App\Entity\Budget;
use App\Entity\User;
use App\Message\Query\Query;

/**
 * The statement of a budget: what it amounts to and every line it holds,
 * the latest first. A line that names an issue the viewer does not see
 * (a private one, for an external user) is there with its quantity, but
 * without its issue.
 *
 * @implements Query<BudgetStatement>
 */
final readonly class ShowBudget implements Query
{
    public function __construct(
        public User $viewer,
        public Budget $budget,
    ) {
    }
}
