<?php

declare(strict_types=1);

namespace App\Entity;

use App\Automation\Definition\RuleDefinition;
use App\Repository\AutomationRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * A rule of a project that acts on its issues by itself: when something
 * happens (or nothing does for a while), if its conditions hold, it does
 * its actions (see Automation\RuleDefinition). It holds on every tracker
 * of the project, or on one when it is limited to it. A rule is
 * configuration, like a linked repository: deleting it deletes the row,
 * and what it did stays in the journal of the issues and in its
 * executions (AutomationRun), under the name it had.
 */
#[ORM\Entity(repositoryClass: AutomationRuleRepository::class)]
#[ORM\Table(name: 'automation_rule')]
class AutomationRule
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

    /** A deactivated rule does nothing and keeps its history. */
    #[ORM\Column]
    private bool $active = true;

    /** The tracker the rule is limited to; every tracker of the project when null. */
    #[ORM\ManyToOne]
    private ?Tracker $tracker;

    /** @var array<string, mixed> the trigger, the conditions and the actions, as RuleDefinition::toArray() gives them */
    #[ORM\Column(type: Types::JSON)]
    private array $definition;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Project $project, string $name, ?Tracker $tracker, RuleDefinition $definition)
    {
        $this->project = $project;
        $this->name = trim($name);
        $this->tracker = $tracker;
        $this->definition = $definition->toArray();
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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function getTracker(): ?Tracker
    {
        return $this->tracker;
    }

    /**
     * Whether the rule holds on the issues of a tracker.
     */
    public function appliesTo(Tracker $tracker): bool
    {
        return null === $this->tracker || $this->tracker->getId() === $tracker->getId();
    }

    public function getDefinition(): RuleDefinition
    {
        return RuleDefinition::fromArray($this->definition);
    }

    /**
     * What the rule says and where it holds change together: a step may
     * name a place of the tracker.
     */
    public function redefine(?Tracker $tracker, RuleDefinition $definition): void
    {
        $this->tracker = $tracker;
        $this->definition = $definition->toArray();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
