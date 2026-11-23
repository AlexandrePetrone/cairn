<?php

declare(strict_types=1);

namespace App\Form\Tag;

use App\Entity\Enum\TagColour;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the tag form collects: a name unique in the project and a colour
 * of the palette. The controller turns it into CreateTag or UpdateTag.
 */
final class TagData
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 40)]
    public ?string $name = null;

    #[Assert\NotNull]
    public ?TagColour $colour = TagColour::Blue;
}
