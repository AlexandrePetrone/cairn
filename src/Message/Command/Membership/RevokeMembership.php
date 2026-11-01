<?php

declare(strict_types=1);

namespace App\Message\Command\Membership;

/**
 * Takes a role back: the membership is deleted, memberships being the
 * current state of access, not its history. Handled by
 * RevokeMembershipHandler.
 */
final readonly class RevokeMembership
{
    public function __construct(
        public int $membershipId,
    ) {
    }
}
