<?php

declare(strict_types=1);

namespace App\Form\Config;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the dialog of a type of link edits.
 */
final class LinkTypeData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 120)]
    public ?string $label = null;

    #[Assert\Length(max: 120)]
    public ?string $inverseLabel = null;
}
