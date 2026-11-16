<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BudgetRepository;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * An amount of time a client committed to on a project (an open order),
 * that its approved lines consume. Its volume is in minutes, shown in the
 * unit of the project. Budgets are optional: a project without a unit
 * shows none of it. Never deleted: a budget is closed, and may end below
 * zero.
 */
#[ORM\Entity(repositoryClass: BudgetRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_budget_project_name', columns: ['project_id', 'name'])]
class Budget
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Project $project;

    #[ORM\Column(length: 80)]
    private string $name;

    /** In minutes. */
    #[ORM\Column]
    private int $volume;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Null while the budget is open: a closed one keeps its lines and takes no new one. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(Project $project, string $name, int $volume)
    {
        $this->project = $project;
        $this->name = trim($name);
        $this->volume = $volume;
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

    public function rename(string $name): void
    {
        $this->name = trim($name);
    }

    public function getVolume(): int
    {
        return $this->volume;
    }

    public function resize(int $volume): void
    {
        $this->volume = $volume;
    }

    public function isOpen(): bool
    {
        return null === $this->closedAt;
    }

    public function close(): void
    {
        $this->closedAt ??= now();
    }

    public function reopen(): void
    {
        $this->closedAt = null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }
}
