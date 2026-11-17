<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A place a workflow uses, at its position among the columns of the kanban.
 */
#[ORM\Entity]
#[ORM\Table(name: 'config_workflow_place')]
#[ORM\UniqueConstraint(name: 'config_workflow_place_unique', columns: ['workflow_id', 'place_id'])]
class WorkflowPlace implements Positioned
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workflow $workflow;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Place $place;

    #[ORM\Column]
    private int $position;

    public function __construct(Workflow $workflow, Place $place, int $position = 0)
    {
        $this->workflow = $workflow;
        $this->place = $place;
        $this->position = $position;
    }

    public function getWorkflow(): Workflow
    {
        return $this->workflow;
    }

    public function getPlace(): Place
    {
        return $this->place;
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
