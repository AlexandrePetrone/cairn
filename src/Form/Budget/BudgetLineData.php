<?php

declare(strict_types=1);

namespace App\Form\Budget;

use App\Budget\Quantities;
use App\Entity\Enum\BudgetLineStatus;
use App\Entity\Issue;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the form of a budget line collects: the issue it is for (when it
 * is written), a label, where it stands and a quantity typed in the unit
 * of the project. The controller turns it into AddBudgetLine or
 * UpdateBudgetLine, the quantity in minutes; what a line must hold is the
 * rule of their handlers.
 */
final class BudgetLineData
{
    public ?Issue $issue = null;

    #[Assert\Length(max: 200)]
    public ?string $label = null;

    #[Assert\NotNull]
    public ?BudgetLineStatus $status = BudgetLineStatus::ToEstimate;

    /** In the unit of the project. */
    #[Assert\Range(min: -Quantities::MAX_TYPED, max: Quantities::MAX_TYPED)]
    public ?float $quantity = null;
}
