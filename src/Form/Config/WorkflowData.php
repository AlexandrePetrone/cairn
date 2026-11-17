<?php

declare(strict_types=1);

namespace App\Form\Config;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the dialog of a workflow edits: its name and the step new issues start on.
 */
final class WorkflowData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 120)]
    public ?string $label = null;

    #[Assert\NotBlank]
    public ?string $initial = null;
}
