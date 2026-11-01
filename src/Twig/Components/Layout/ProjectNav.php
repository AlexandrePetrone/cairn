<?php

declare(strict_types=1);

namespace App\Twig\Components\Layout;

use App\Entity\Project;
use App\Entity\Tracker;
use App\Repository\TrackerRepository;
use App\Security\Viewer;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The sidebar of a project: its issue lists, then its trackers. The
 * current entry is told from the current route.
 */
#[AsTwigComponent('Layout:ProjectNav')]
final class ProjectNav
{
    public Project $project;

    public function __construct(
        private readonly TrackerRepository $trackers,
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
        return $this->trackers->findByProject($this->project, includePrivate: $this->viewer->get()->isInternal());
    }
}
