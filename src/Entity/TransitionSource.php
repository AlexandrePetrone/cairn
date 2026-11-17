<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A place an action may be triggered from.
 */
#[ORM\Entity]
#[ORM\Table(name: 'config_transition_source')]
#[ORM\UniqueConstraint(name: 'config_transition_source_unique', columns: ['transition_id', 'place_id'])]
class TransitionSource
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
    private Place $place;

    public function __construct(Transition $transition, Place $place)
    {
        $this->transition = $transition;
        $this->place = $place;
    }

    public function getTransition(): Transition
    {
        return $this->transition;
    }

    public function getPlace(): Place
    {
        return $this->place;
    }
}
