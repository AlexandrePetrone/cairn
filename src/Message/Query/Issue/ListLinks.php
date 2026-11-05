<?php

declare(strict_types=1);

namespace App\Message\Query\Issue;

use App\Entity\Issue;
use App\Entity\IssueLink;
use App\Entity\User;
use App\Message\Query\Query;

/**
 * The links of an issue whose other end the viewer sees, in both
 * directions, oldest first. The issue itself is the caller's to check.
 *
 * @implements Query<list<IssueLink>>
 */
final readonly class ListLinks implements Query
{
    public function __construct(
        public User $viewer,
        public Issue $issue,
    ) {
    }
}
