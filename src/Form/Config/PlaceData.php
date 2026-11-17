<?php

declare(strict_types=1);

namespace App\Form\Config;

use App\Entity\Enum\PlaceStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the dialog of a step of the instance edits.
 */
final class PlaceData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 120)]
    public ?string $label = null;

    #[Assert\NotNull]
    public ?PlaceStatus $status = PlaceStatus::Open;
}
