<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RoleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A role that can be granted on a workspace or a project. Its id is what
 * memberships and actions name, fixed once created; the label may change
 * freely. Roles are ordered from the broadest to the narrowest: a direct
 * grant may only widen a role inherited from the workspace.
 */
#[ORM\Entity(repositoryClass: RoleRepository::class)]
#[ORM\Table(name: 'config_role')]
class Role implements Positioned
{
    #[ORM\Id]
    #[ORM\Column(length: 60)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $label;

    /** The role can only be granted to internal users. */
    #[ORM\Column]
    private bool $internalOnly;

    /** Its holders on a project are subscribed to every new issue of the project. */
    #[ORM\Column]
    private bool $followsNewIssues;

    #[ORM\Column]
    private int $position;

    public function __construct(string $id, string $label, bool $internalOnly = false, bool $followsNewIssues = false, int $position = 0)
    {
        $this->id = $id;
        $this->label = trim($label);
        $this->internalOnly = $internalOnly;
        $this->followsNewIssues = $followsNewIssues;
        $this->position = $position;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function rename(string $label): void
    {
        $this->label = trim($label);
    }

    public function isInternalOnly(): bool
    {
        return $this->internalOnly;
    }

    public function followsNewIssues(): bool
    {
        return $this->followsNewIssues;
    }

    public function makeInternalOnly(): void
    {
        $this->internalOnly = true;
    }

    public function openToExternalUsers(): void
    {
        $this->internalOnly = false;
    }

    public function followNewIssues(): void
    {
        $this->followsNewIssues = true;
    }

    public function stopFollowingNewIssues(): void
    {
        $this->followsNewIssues = false;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }
}
