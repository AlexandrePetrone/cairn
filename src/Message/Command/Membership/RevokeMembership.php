<?php

declare(strict_types=1);

namespace App\Message\Command\Membership;

/**
 * Takes a role back: the membership is deleted, memberships being the
 * current state of access, not its history. Without an actor, the
 * administration takes it back; with one, the actor needs
 * `project.manage_members` and takes back roles held on a project only.
 * Handled by RevokeMembershipHandler.
 */
final readonly class RevokeMembership
{
    public function __construct(
        public int $membershipId,
        public ?int $actorId = null,
    ) {
    }
}
