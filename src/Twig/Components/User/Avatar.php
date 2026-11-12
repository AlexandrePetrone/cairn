<?php

declare(strict_types=1);

namespace App\Twig\Components\User;

use App\Entity\User;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The avatar of a person, wherever one is shown: their picture or their
 * initials, on the colour of their kind (internal or external). Nobody
 * (`user` null) is the "unassigned" avatar. The one place that turns a
 * User into what `Ui:Avatar` draws; other attributes go to it.
 */
#[AsTwigComponent('User:Avatar')]
final class Avatar
{
    public ?User $user = null;

    public string $size = 'md';
}
