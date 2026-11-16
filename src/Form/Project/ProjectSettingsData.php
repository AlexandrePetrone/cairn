<?php

declare(strict_types=1);

namespace App\Form\Project;

use App\Entity\Enum\BudgetUnit;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the general settings of a project collect; the controller turns it
 * into an UpdateProject command carrying the actor.
 */
final class ProjectSettingsData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    public ?string $description = null;

    /** Null, the project has no budget. */
    public ?BudgetUnit $budgetUnit = null;
}
