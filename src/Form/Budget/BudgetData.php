<?php

declare(strict_types=1);

namespace App\Form\Budget;

use App\Budget\Quantities;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the budget form collects: a name unique in the project, a volume
 * typed in the unit of the project and, once it exists, whether it is
 * closed. The controller turns it into CreateBudget or UpdateBudget, the
 * volume in minutes.
 */
final class BudgetData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 80)]
    public ?string $name = null;

    /** In the unit of the project. */
    #[Assert\NotNull]
    #[Assert\Range(min: 0, max: Quantities::MAX_TYPED)]
    public ?float $volume = null;

    public bool $closed = false;
}
