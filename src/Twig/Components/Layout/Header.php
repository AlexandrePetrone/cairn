<?php

declare(strict_types=1);

namespace App\Twig\Components\Layout;

use App\Entity\Project;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The application header: the logo, the project the page belongs to when
 * there is one, and the menu of the signed-in user.
 */
#[AsTwigComponent('Layout:Header')]
final class Header
{
    public ?Project $project = null;

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function getUser(): User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : throw new \LogicException('The header is only rendered for signed-in users.');
    }
}
