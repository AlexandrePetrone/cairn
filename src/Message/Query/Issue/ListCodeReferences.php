<?php

declare(strict_types=1);

namespace App\Message\Query\Issue;

use App\Entity\CodeReference;
use App\Entity\Issue;
use App\Entity\User;
use App\Message\Query\Query;

/**
 * The commits and merge requests the forges told about an issue, for the
 * side panel: merge requests first (open, then merged, then closed, the
 * latest change first), then commits, the latest first. Internal users
 * only: an external viewer gets nothing, whatever the template does.
 *
 * @implements Query<list<CodeReference>>
 */
final readonly class ListCodeReferences implements Query
{
    public function __construct(
        public User $viewer,
        public Issue $issue,
    ) {
    }
}
