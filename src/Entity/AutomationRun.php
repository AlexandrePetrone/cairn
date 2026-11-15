<?php

declare(strict_types=1);

namespace App\Entity;

use App\Automation\Definition\TriggerType;
use App\Automation\StepResult;
use App\Entity\Enum\RunOutcome;
use App\Repository\AutomationRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * One execution of an automation rule on an issue: what triggered it,
 * how it ended, what each action did. Written when a rule whose trigger
 * and conditions matched ran, or was stopped; a rule that did not match
 * leaves nothing. History of the project: it outlives the rule, whose
 * name of the time it keeps. It is also how a rule that waits for time
 * knows it already acted since someone last touched the issue.
 */
#[ORM\Entity(repositoryClass: AutomationRunRepository::class)]
#[ORM\Table(name: 'automation_run')]
#[ORM\Index(name: 'idx_automation_run_project', columns: ['project_id', 'at'])]
#[ORM\Index(name: 'idx_automation_run_rule_issue', columns: ['rule_id', 'issue_id'])]
class AutomationRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Project $project;

    /** Null once the rule was deleted. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?AutomationRule $rule;

    #[ORM\Column(length: 120)]
    private string $ruleName;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Issue $issue;

    #[ORM\Column]
    private \DateTimeImmutable $at;

    /** A TriggerType value, kept as text: the history outlives the list of triggers. */
    #[ORM\Column(name: '`trigger`', length: 30)]
    private string $trigger;

    /** Who did what triggered the rule; null when time did, or another rule. */
    #[ORM\ManyToOne]
    private ?User $actor;

    #[ORM\Column(length: 10, enumType: RunOutcome::class)]
    private RunOutcome $outcome;

    /** @var list<array<string, mixed>> one line per action: `action`, `result` (applied, unchanged, skipped), and a `value` or a `reason` */
    #[ORM\Column(type: Types::JSON)]
    private array $steps;

    /**
     * @param list<StepResult> $steps
     */
    public function __construct(AutomationRule $rule, Issue $issue, TriggerType $trigger, ?User $actor, RunOutcome $outcome, array $steps = [])
    {
        $this->project = $rule->getProject();
        $this->rule = $rule;
        $this->ruleName = $rule->getName();
        $this->issue = $issue;
        $this->trigger = $trigger->value;
        $this->actor = $actor;
        $this->outcome = $outcome;
        $this->steps = array_map(static fn (StepResult $step): array => $step->toArray(), $steps);
        $this->at = now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getRule(): ?AutomationRule
    {
        return $this->rule;
    }

    public function getRuleName(): string
    {
        return $this->ruleName;
    }

    public function getIssue(): Issue
    {
        return $this->issue;
    }

    public function getAt(): \DateTimeImmutable
    {
        return $this->at;
    }

    public function getTrigger(): string
    {
        return $this->trigger;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getOutcome(): RunOutcome
    {
        return $this->outcome;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }
}
