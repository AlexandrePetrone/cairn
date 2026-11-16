<?php

declare(strict_types=1);

namespace App\Twig;

use App\Budget\Quantities;
use App\Entity\Enum\BudgetUnit;
use App\Entity\Project;
use Twig\Attribute\AsTwigFilter;

/**
 * Budgets are in minutes; templates show them in the unit of their
 * project: `{{ summary.consumed|quantity(project) }}`.
 */
final class BudgetExtension
{
    public function __construct(
        private readonly Quantities $quantities,
    ) {
    }

    #[AsTwigFilter('quantity')]
    public function quantity(int $minutes, Project $project): string
    {
        return $this->quantities->format($minutes, $project->getBudgetUnit() ?? BudgetUnit::Days);
    }
}
