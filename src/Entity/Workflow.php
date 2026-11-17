<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WorkflowRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The places an issue goes through, the actions that lead from one to
 * another and the fields asked of it. Trackers follow a workflow, named by
 * its id. What it holds are rows of their own (WorkflowPlace,
 * WorkflowField, Transition): Config\DefinitionLoader puts them together.
 */
#[ORM\Entity(repositoryClass: WorkflowRepository::class)]
#[ORM\Table(name: 'config_workflow')]
class Workflow implements Positioned
{
    #[ORM\Id]
    #[ORM\Column(length: 60)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $label;

    /** The place of a newly created issue: one of the places of the workflow. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Place $initial;

    #[ORM\Column]
    private int $position;

    public function __construct(string $id, string $label, Place $initial, int $position = 0)
    {
        $this->id = $id;
        $this->label = trim($label);
        $this->initial = $initial;
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

    public function getInitial(): Place
    {
        return $this->initial;
    }

    public function rename(string $label): void
    {
        $this->label = trim($label);
    }

    public function startOn(Place $initial): void
    {
        $this->initial = $initial;
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
