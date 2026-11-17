<?php

declare(strict_types=1);

namespace App\Form\Config;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the dialog of a role edits.
 */
final class RoleData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 120)]
    public ?string $label = null;

    public bool $internalOnly = false;

    public bool $followsNewIssues = false;
}
