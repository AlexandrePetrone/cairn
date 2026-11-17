<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TemplateTrackerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A tracker a project template gives the projects made from it: what a
 * Tracker says (name, description, workflow, private, position), without
 * a project. The workflow is chosen once, as for a tracker: the rules of
 * the template may name its places.
 */
#[ORM\Entity(repositoryClass: TemplateTrackerRepository::class)]
#[ORM\Table(name: 'template_tracker')]
class TemplateTracker implements Positioned
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ProjectTemplate $template;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** The workflow, an element of the configuration: only its key is read from here. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'workflow_id', nullable: false)]
    private Workflow $workflow;

    #[ORM\Column]
    private bool $private = false;

    #[ORM\Column]
    private int $position;

    public function __construct(ProjectTemplate $template, string $name, Workflow $workflow, int $position = 0)
    {
        $this->template = $template;
        $this->name = trim($name);
        $this->workflow = $workflow;
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTemplate(): ProjectTemplate
    {
        return $this->template;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = trim($name);
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = '' === trim((string) $description) ? null : $description;
    }

    public function getWorkflowId(): string
    {
        return $this->workflow->getId();
    }

    public function isPrivate(): bool
    {
        return $this->private;
    }

    public function makePrivate(): void
    {
        $this->private = true;
    }

    public function makePublic(): void
    {
        $this->private = false;
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
