<?php

declare(strict_types=1);

namespace App\Message\Query\Issue;

use App\Entity\User;
use App\Message\Query\Query;

/**
 * One issue as its page shows it to the viewer, or null when it does not
 * exist or the viewer may not see it: both read as "not found".
 *
 * @implements Query<IssueDetail|null>
 */
final readonly class ShowIssue implements Query
{
    public function __construct(
        public User $viewer,
        public int $projectId,
        public int $number,
    ) {
    }
}
