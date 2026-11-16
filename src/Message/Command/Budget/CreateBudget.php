<?php

declare(strict_types=1);

namespace App\Message\Command\Budget;

use App\Budget\Quantities;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creates an open budget in a project that counts budgets, its name
 * unique there. The volume is in minutes; zero is a budget still to be
 * signed. The actor needs `budget.manage`. Handled by CreateBudgetHandler,
 * which returns the Budget.
 */
final readonly class CreateBudget
{
    public function __construct(
        public int $actorId,
        public int $projectId,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 80)]
        public string $name,
        #[Assert\Range(min: 0, max: Quantities::MAX_MINUTES)]
        public int $volume,
    ) {
    }
}
