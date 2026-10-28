<?php

declare(strict_types=1);

namespace App\Twig\Components\Layout;

use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Repository\TrackerRepository;
use Symfony\Bundle\SecurityBundle\Security;
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
        private readonly Security $security,
    ) {
    }

    /**
     * Private trackers are hidden from external users.
     *
     * @return list<Tracker>
     */
    public function getTrackers(): array
    {
        $user = $this->security->getUser();

        return $this->trackers->findByProject($this->project, includePrivate: $user instanceof User && $user->isInternal());
    }
}
