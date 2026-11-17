<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A role allowed to trigger an action.
 */
#[ORM\Entity]
#[ORM\Table(name: 'config_transition_role')]
#[ORM\UniqueConstraint(name: 'config_transition_role_unique', columns: ['transition_id', 'role_id'])]
class TransitionRole
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Transition $transition;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Role $role;

    public function __construct(Transition $transition, Role $role)
    {
        $this->transition = $transition;
        $this->role = $role;
    }

    public function getTransition(): Transition
    {
        return $this->transition;
    }

    public function getRole(): Role
    {
        return $this->role;
    }
}
