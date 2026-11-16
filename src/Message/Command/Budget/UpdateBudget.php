<?php

declare(strict_types=1);

namespace App\Message\Command\Budget;

use App\Budget\Quantities;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes a budget, PATCH style: a property left null keeps its value.
 * Closed, it keeps its lines and takes no new one; it is reopened the same
 * way. The actor needs `budget.manage`. Handled by UpdateBudgetHandler.
 */
final readonly class UpdateBudget
{
    public function __construct(
        public int $actorId,
        public int $budgetId,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 80)]
        public ?string $name = null,
        /** In minutes. */
        #[Assert\Range(min: 0, max: Quantities::MAX_MINUTES)]
        public ?int $volume = null,
        public ?bool $closed = null,
    ) {
    }
}
