<?php

declare(strict_types=1);

namespace App\Message\Command\Budget;

use App\Budget\Quantities;
use App\Entity\Enum\BudgetLineStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Writes a line on an open budget: what it is for (an issue of the
 * project, a label, or both), where it stands, and its quantity in
 * minutes unless it is still to estimate. Nothing is written on the
 * issue: no history, no notification. The actor needs `budget.manage`.
 * Handled by AddBudgetLineHandler, which returns the BudgetLine.
 */
final readonly class AddBudgetLine
{
    public function __construct(
        public int $actorId,
        public int $budgetId,
        public BudgetLineStatus $status = BudgetLineStatus::ToEstimate,
        /** In minutes, negative to give time back; ignored for a line to estimate. */
        #[Assert\Range(min: -Quantities::MAX_MINUTES, max: Quantities::MAX_MINUTES)]
        public ?int $quantity = null,
        public ?int $issueId = null,
        #[Assert\Length(max: 200)]
        public ?string $label = null,
    ) {
    }
}
