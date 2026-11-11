<?php

declare(strict_types=1);

namespace App\Message\Query\Project;

use App\Entity\Project;
use App\Entity\User;
use App\Message\Query\Query;

/**
 * How many issues each list of a project's navigation holds for the
 * viewer: the open issues they see, counted as the lists come, whatever
 * the arrangement someone keeps of a list. The project is one the viewer
 * may view: the caller checked it.
 *
 * @implements Query<ProjectIssueCounts>
 */
final readonly class CountProjectIssues implements Query
{
    public function __construct(
        public User $viewer,
        public Project $project,
    ) {
    }
}
