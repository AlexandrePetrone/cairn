<?php

declare(strict_types=1);

namespace App\Message\Query\Project;

use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Message\Query\Query;

/**
 * The trackers of a project as the viewer sees them, in display order:
 * private ones for internal users only, archived ones only when asked
 * for: they keep their issues, so the navigation and the lists show them,
 * but nothing new goes in them. The one
 * read that applies the privacy of trackers; the administration and the
 * commands, which see every tracker of a project, use
 * TrackerRepository::findByProject(). The project is one the viewer may
 * view: the caller checked it.
 *
 * @implements Query<list<Tracker>>
 */
final readonly class ListTrackers implements Query
{
    public function __construct(
        public User $viewer,
        public Project $project,
        public bool $archived = false,
    ) {
    }
}
