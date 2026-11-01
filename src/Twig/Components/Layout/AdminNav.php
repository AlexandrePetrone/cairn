<?php

declare(strict_types=1);

namespace App\Twig\Components\Layout;

use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The sidebar of the administration: projects, workspaces and users, with
 * how many there are. The current entry is told from the current route.
 */
#[AsTwigComponent('Layout:AdminNav')]
final class AdminNav
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly WorkspaceRepository $workspaces,
        private readonly UserRepository $users,
    ) {
    }

    public function getProjectCount(): int
    {
        return $this->projects->count();
    }

    public function getWorkspaceCount(): int
    {
        return $this->workspaces->count();
    }

    public function getUserCount(): int
    {
        return $this->users->count();
    }
}
