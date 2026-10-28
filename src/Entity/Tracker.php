<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrackerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * A category of issues within a project, bound to one workflow of the
 * configuration for good. A private tracker hides all of its issues from
 * external users. Position orders the creation form and the menu.
 */
#[ORM\Entity(repositoryClass: TrackerRepository::class)]
class Tracker
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Project $project;

    #[ORM\Column(length: 120)]
    private string $name;

    /** Shown to client users when they pick a category. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 60)]
    private string $workflowId;

    #[ORM\Column]
    private bool $private = false;

    #[ORM\Column]
    private int $position;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Project $project, string $name, string $workflowId, int $position = 0)
    {
        $this->project = $project;
        $this->name = trim($name);
        $this->workflowId = $workflowId;
        $this->position = $position;
        $this->createdAt = now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getName(): string
    {
        return $this->name;
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
        return $this->workflowId;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
