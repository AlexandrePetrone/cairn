<?php

declare(strict_types=1);

namespace App\Message\Query\Release;

use App\Entity\Project;
use App\Entity\User;
use App\Message\Query\Query;

/**
 * The releases of a project, open ones first by planned date, then the
 * others latest first, each with its issues counted by status as the
 * viewer sees them.
 *
 * @implements Query<list<ReleaseSummary>>
 */
final readonly class ListReleases implements Query
{
    public function __construct(
        public User $viewer,
        public Project $project,
    ) {
    }
}
