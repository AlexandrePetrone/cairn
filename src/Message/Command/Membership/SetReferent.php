<?php

declare(strict_types=1);

namespace App\Message\Command\Membership;

/**
 * Makes the holder of a membership the referent of its role where the
 * membership applies, or takes the mark back. One referent per role on a
 * project and on a workspace: designating one withdraws the other.
 * Without an actor, the administration decides; with one, the actor
 * needs `project.manage_members` and decides for the memberships of a
 * project only. Handled by SetReferentHandler.
 */
final readonly class SetReferent
{
    public function __construct(
        public int $membershipId,
        public bool $referent,
        public ?int $actorId = null,
    ) {
    }
}
