<?php

declare(strict_types=1);

namespace App\Message\Query\Issue;

use App\Entity\Issue;
use App\Entity\User;
use App\Message\Query\Query;

/**
 * The people following an issue who may still see it, by name: a
 * subscriber the issue became invisible to (made private, access
 * revoked) is neither shown nor written to. The issue itself is the
 * caller's to check.
 *
 * @implements Query<list<User>>
 */
final readonly class ListSubscribers implements Query
{
    public function __construct(
        public Issue $issue,
    ) {
    }
}
