<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\BudgetUnit;
use App\Repository\ProjectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * A project of a workspace. Its key, unique within the workspace, is the
 * second part of an issue identifier (`MD-SPT-128`). Archived projects stay
 * readable but take no new activity.
 */
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_project_workspace_key', columns: ['workspace_id', 'key'])]
class Project
{
    /**
     * What a project key is made of: 2 to 5 uppercase letters or digits,
     * one letter at least, so that a date (`2024-11-18`) never reads as an
     * issue key. The same as a workspace key today, written apart since
     * each may change on its own. Without anchors, for routes and longer
     * patterns.
     */
    public const string KEY_FORMAT = '(?=[0-9]*[A-Z])[A-Z0-9]{2,5}';

    public const string KEY_PATTERN = '/^'.self::KEY_FORMAT.'$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Workspace $workspace;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(name: '`key`', length: 5)]
    private string $key;

    /** Shown to client users on the project pages. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $archived = false;

    /**
     * The unit the budgets of the project show in; null, the project has
     * no budget. Only what is shown: quantities are in minutes.
     */
    #[ORM\Column(length: 10, nullable: true, enumType: BudgetUnit::class)]
    private ?BudgetUnit $budgetUnit = null;

    /** Last issue number handed out; numbers are never reused. */
    #[ORM\Column]
    private int $issueCounter = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Workspace $workspace, string $name, string $key)
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid project key (2 to 5 uppercase letters or digits, with a letter).', $key));
        }

        $this->workspace = $workspace;
        $this->name = trim($name);
        $this->key = $key;
        $this->createdAt = now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkspace(): Workspace
    {
        return $this->workspace;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = trim($name);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Prefix of the project's issue identifiers, e.g. `MD-SPT`.
     */
    public function getIssuePrefix(): string
    {
        return $this->workspace->getKey().'-'.$this->key;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = '' === trim((string) $description) ? null : $description;
    }

    /**
     * Hands out the next issue number. Callers lock the project row first
     * so that two concurrent creations cannot draw the same number.
     */
    public function allocateIssueNumber(): int
    {
        return ++$this->issueCounter;
    }

    /**
     * Hands out a block of numbers at once, for issues written later (an
     * import), and returns the first one. Callers lock the project row
     * first, as for one number.
     */
    public function reserveIssueNumbers(int $count): int
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('At least one number is reserved.');
        }
        $first = $this->issueCounter + 1;
        $this->issueCounter += $count;

        return $first;
    }

    public function getBudgetUnit(): ?BudgetUnit
    {
        return $this->budgetUnit;
    }

    /** Whether the project counts budgets. */
    public function hasBudgets(): bool
    {
        return null !== $this->budgetUnit;
    }

    public function countBudgetsIn(?BudgetUnit $unit): void
    {
        $this->budgetUnit = $unit;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function archive(): void
    {
        $this->archived = true;
    }

    public function unarchive(): void
    {
        $this->archived = false;
    }

    /**
     * Archived itself or through its workspace: readable, but no one acts
     * on it any more.
     */
    public function isReadOnly(): bool
    {
        return $this->archived || $this->workspace->isArchived();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
