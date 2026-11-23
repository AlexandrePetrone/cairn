<?php

declare(strict_types=1);

namespace App\Message\Query\Tag;

use App\Entity\Project;
use App\Entity\Tag;
use App\Message\Query\Query;

/**
 * The tags of a project, by name, for whoever reads the project: none of
 * them is private.
 *
 * @implements Query<list<Tag>>
 */
final readonly class ListTags implements Query
{
    public function __construct(
        public Project $project,
    ) {
    }
}
