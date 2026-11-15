<?php

declare(strict_types=1);

namespace App\Message\Query\Project;

use App\Entity\Project;
use App\Message\Query\Query;

/**
 * What a project holds, private things included: what the administration
 * reads before deleting it for good.
 *
 * @implements Query<ProjectContents>
 */
final readonly class ShowProjectContents implements Query
{
    public function __construct(
        public Project $project,
    ) {
    }
}
