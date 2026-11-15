<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Membership;
use App\Entity\User;

/**
 * Who answers for one role on one project, and why: the mark of the
 * project, the mark of its workspace, and whether each still holds (an
 * active account, the same role on the project). The project replaces
 * its workspace; a mark that no longer holds counts as none, so the
 * workspace answers again behind a project referent who cannot.
 */
final readonly class RoleReferent
{
    public function __construct(
        public ?Membership $own,
        public bool $ownHolds,
        public ?Membership $inherited,
        public bool $inheritedHolds,
    ) {
    }

    /**
     * The membership the role answers through, null for nobody.
     */
    public function membership(): ?Membership
    {
        return match (true) {
            $this->ownHolds => $this->own,
            $this->inheritedHolds => $this->inherited,
            default => null,
        };
    }

    public function user(): ?User
    {
        return $this->membership()?->getUser();
    }

    /**
     * Whether the referent is the one of the workspace, the project naming none that answers.
     */
    public function isInherited(): bool
    {
        return !$this->ownHolds && $this->inheritedHolds;
    }

    /**
     * The referent of the workspace the project's own replaces, if any.
     */
    public function replaced(): ?Membership
    {
        return $this->ownHolds ? $this->inherited : null;
    }

    /**
     * Who is marked whether the mark holds or not, on the project first.
     */
    public function marked(): ?User
    {
        return ($this->own ?? $this->inherited)?->getUser();
    }
}
