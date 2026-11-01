<?php

declare(strict_types=1);

namespace App\Twig\Components\Layout;

use App\Entity\Project;
use App\Entity\User;
use App\Security\Viewer;
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
        private readonly Viewer $viewer,
    ) {
    }

    public function getUser(): User
    {
        return $this->viewer->get();
    }
}
