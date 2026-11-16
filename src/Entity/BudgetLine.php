<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\BudgetLineStatus;
use App\Repository\BudgetLineRepository;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * A line of a budget, written by hand by whoever manages the budgets: a
 * quantity in minutes, a status, and what it is for, an issue of the
 * project, a label, or both. Nothing ties it to the workflow of the issue
 * nor to its history: the issue only shows the lines that name it. A line
 * to estimate has no quantity yet, nor one refused before it was estimated;
 * only an approved line is consumed.
 */
#[ORM\Entity(repositoryClass: BudgetLineRepository::class)]
class BudgetLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Budget $budget;

    /** Chosen when the line is written, never changed. */
    #[ORM\ManyToOne]
    private ?Issue $issue;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $label = null;

    /** In minutes; null while the line is to estimate, or refused before it was. Negative gives time back. */
    #[ORM\Column(nullable: true)]
    private ?int $quantity = null;

    #[ORM\Column(length: 20, enumType: BudgetLineStatus::class)]
    private BudgetLineStatus $status = BudgetLineStatus::ToEstimate;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Budget $budget, ?Issue $issue, ?string $label)
    {
        $this->budget = $budget;
        $this->issue = $issue;
        $this->describe($label);
        $this->createdAt = now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBudget(): Budget
    {
        return $this->budget;
    }

    public function getIssue(): ?Issue
    {
        return $this->issue;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function describe(?string $label): void
    {
        $this->label = '' === trim((string) $label) ? null : trim((string) $label);
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function getStatus(): BudgetLineStatus
    {
        return $this->status;
    }

    /**
     * Its manager marks where the line stands, as the client answers, and
     * what it amounts to: a line to estimate has no quantity, whatever is
     * given. Zero is a quantity: an estimate of nothing.
     */
    public function mark(BudgetLineStatus $status, ?int $quantity): void
    {
        $this->status = $status;
        $this->quantity = BudgetLineStatus::ToEstimate === $status ? null : $quantity;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
