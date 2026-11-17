<?php

declare(strict_types=1);

namespace App\Message\Command\Budget;

use App\Budget\Quantities;
use App\Entity\Enum\BudgetLineStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes a line of a budget, PATCH style: a property left null keeps its
 * value. Its status is how its manager says the client approved or
 * refused it; back to estimate, it keeps its quantity. Zero is a quantity,
 * so taking the quantity away is said apart (`withoutQuantity`): only a
 * line to estimate or refused may then stay as it is. The issue it names never changes.
 * The actor needs `budget.manage`. Handled by UpdateBudgetLineHandler.
 */
final readonly class UpdateBudgetLine
{
    public function __construct(
        public int $actorId,
        public int $lineId,
        public ?BudgetLineStatus $status = null,
        /** In minutes. */
        #[Assert\Range(min: -Quantities::MAX_MINUTES, max: Quantities::MAX_MINUTES)]
        public ?int $quantity = null,
        /** An empty string clears it, on a line that names an issue. */
        #[Assert\Length(max: 200)]
        public ?string $label = null,
        /** Takes the quantity away, whatever `quantity` says. */
        public bool $withoutQuantity = false,
    ) {
    }
}
