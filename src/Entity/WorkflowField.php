<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A field a workflow asks for, at its position in the form of an issue.
 */
#[ORM\Entity]
#[ORM\Table(name: 'config_workflow_field')]
#[ORM\UniqueConstraint(name: 'config_workflow_field_unique', columns: ['workflow_id', 'field_id'])]
class WorkflowField implements Positioned
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
    private Field $field;

    #[ORM\Column]
    private bool $required;

    #[ORM\Column]
    private int $position;

    public function __construct(Workflow $workflow, Field $field, bool $required = false, int $position = 0)
    {
        $this->workflow = $workflow;
        $this->field = $field;
        $this->required = $required;
        $this->position = $position;
    }

    public function getWorkflow(): Workflow
    {
        return $this->workflow;
    }

    public function getField(): Field
    {
        return $this->field;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): void
    {
        $this->required = $required;
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
