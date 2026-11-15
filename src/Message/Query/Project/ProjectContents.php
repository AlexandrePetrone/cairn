<?php

declare(strict_types=1);

namespace App\Message\Query\Project;

use App\Entity\Project;

/**
 * A project and the number of things that would go with it.
 */
final readonly class ProjectContents
{
    public function __construct(
        public Project $project,
        public int $issues,
        public int $comments,
        public int $files,
        public int $trackers,
        public int $releases,
        public int $members,
        public int $repositories,
        public int $rules,
    ) {
    }
}
