<?php

declare(strict_types=1);

namespace App\Message\Query\Project;

use App\Entity\User;
use App\Message\Query\Query;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The projects the viewer may open, with the volume of their issues as
 * the viewer sees it: every project for an internal user, the projects of
 * their memberships for an external one. Sorted by workspace, archived
 * ones last, then by name; `recent` keeps instead the active projects
 * whose issues moved last, at most that many. `q` finds a project by its
 * name, its key or the name of its workspace.
 *
 * @implements Query<list<ProjectSummary>>
 */
final readonly class ListVisibleProjects implements Query
{
    public function __construct(
        public User $viewer,
        public string $q = '',
        #[Assert\Positive]
        public ?int $recent = null,
    ) {
    }
}
