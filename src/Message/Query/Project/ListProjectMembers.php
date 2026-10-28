<?php

declare(strict_types=1);

namespace App\Message\Query\Project;

use App\Entity\Project;
use App\Entity\User;
use App\Message\Query\Query;

/**
 * The people holding a role on a project, directly or through its
 * workspace: the assignee filter, the assignment choices.
 *
 * @implements Query<list<User>>
 */
final readonly class ListProjectMembers implements Query
{
    public function __construct(
        public Project $project,
    ) {
    }
}
