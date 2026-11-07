<?php

declare(strict_types=1);

namespace App\Twig\Components\Layout;

use App\Entity\Project;
use App\Entity\Tracker;
use App\Message\Query\Project\ListTrackers;
use App\Message\QueryBus;
use App\Repository\ReleaseRepository;
use App\Security\Viewer;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The sidebar of a project: its issue lists and its releases, then its
 * trackers. The current entry is told from the current route.
 */
#[AsTwigComponent('Layout:ProjectNav')]
final class ProjectNav
{
    public Project $project;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly ReleaseRepository $releases,
        private readonly Viewer $viewer,
    ) {
    }

    /**
     * Private trackers are hidden from external users.
     *
     * @return list<Tracker>
     */
    public function getTrackers(): array
    {
        return $this->queries->ask(new ListTrackers($this->viewer->get(), $this->project));
    }

    /**
     * Releases are optional: a project shows them once it has one, or to
     * whoever may create the first.
     */
    public function hasReleases(): bool
    {
        return $this->releases->hasReleases($this->project);
    }
}
