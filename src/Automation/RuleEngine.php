<?php

declare(strict_types=1);

namespace App\Automation;

use App\Automation\Definition\RuleDefinition;
use App\Automation\Definition\RuleStep;
use App\Automation\Definition\TriggerType;
use App\Entity\AutomationRule;
use App\Entity\AutomationRun;
use App\Entity\Enum\RunOutcome;
use App\Entity\Issue;
use App\Entity\User;
use App\Event\CommentAdded;
use App\Event\IssueCreated;
use App\Event\IssueEvent;
use App\Event\IssueMoved;
use App\Event\IssueTransitioned;
use App\Event\IssueUpdated;
use App\Repository\AutomationRuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Runs the automation rules of a project when something happens to one
 * of its issues. It listens to the domain events inside the command's
 * transaction, as SubscriptionRules does, after the other listeners: a
 * rule whose trigger and conditions match is given to RuleExecutor, and
 * what it did is kept as an AutomationRun; rules run in the order they
 * were written. The `#[AsEventListener]` attributes are the whole list
 * of what may trigger a rule.
 *
 * What a rule does is recorded as events too, so rules follow one
 * another. Two limits keep that finite: a rule runs once per issue for
 * everything that follows from one thing someone did (the chain), and no
 * more than MAX_CHAIN rules run inside one another; the rule that would
 * be one too many is not run, and an execution says so.
 *
 * The rules that wait for time are started from outside (TimeRuleRunner,
 * through `runTimed()`), then follow the same path.
 */
final class RuleEngine implements ResetInterface
{
    public const int MAX_CHAIN = 3;

    /** How many rules are running inside one another. */
    private int $depth = 0;

    /** @var array<string, true> the rules that already ran on an issue since what started the chain under way */
    private array $ran = [];

    public function __construct(
        private readonly AutomationRuleRepository $rules,
        private readonly RuleConditions $conditions,
        private readonly RuleExecutor $executor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[AsEventListener(event: IssueCreated::class, priority: -100)]
    #[AsEventListener(event: CommentAdded::class, priority: -100)]
    #[AsEventListener(event: IssueTransitioned::class, priority: -100)]
    #[AsEventListener(event: IssueMoved::class, priority: -100)]
    #[AsEventListener(event: IssueUpdated::class, priority: -100)]
    public function onEvent(IssueEvent $event): void
    {
        $issue = $event->issue;
        if ($issue->getProject()->isReadOnly()) {
            return;
        }
        // An event no rule recorded is what someone did: a chain starts, and ends once every rule reacted to it.
        $starts = 0 === $this->depth;
        try {
            foreach ($this->rules->findActive($issue->getProject()) as $rule) {
                $definition = $this->definitionOf($rule);
                if (null === $definition || !$rule->appliesTo($issue->getTracker()) || !$this->triggers($definition->trigger, $event)) {
                    continue;
                }
                if ($this->conditions->hold($definition, $issue, $event->actor)) {
                    $this->run($rule, $definition, $issue, $event->actor);
                }
            }
        } finally {
            if ($starts) {
                $this->ran = [];
            }
        }
    }

    /**
     * Runs a rule that waits for time on an issue it is due on, when its
     * conditions hold.
     *
     * @return bool whether the rule ran
     */
    public function runTimed(AutomationRule $rule, Issue $issue): bool
    {
        $definition = $this->definitionOf($rule);
        if (null === $definition || !$definition->trigger->type->isTimed() || !$rule->appliesTo($issue->getTracker()) || $issue->getProject()->isReadOnly()) {
            return false;
        }
        if (!$this->conditions->hold($definition, $issue, null)) {
            return false;
        }
        try {
            $this->run($rule, $definition, $issue, null);
        } finally {
            $this->ran = [];
        }

        return true;
    }

    public function reset(): void
    {
        $this->depth = 0;
        $this->ran = [];
    }

    private function run(AutomationRule $rule, RuleDefinition $definition, Issue $issue, ?User $actor): void
    {
        $key = $rule->getId().'-'.spl_object_id($issue);
        if (isset($this->ran[$key])) {
            return;
        }
        $trigger = $definition->trigger->type;
        if ($this->depth >= self::MAX_CHAIN) {
            $this->entityManager->persist(new AutomationRun($rule, $issue, $trigger, $actor, RunOutcome::Stopped));

            return;
        }

        $this->ran[$key] = true;
        ++$this->depth;
        try {
            $steps = $this->executor->apply($rule->getName(), $definition, $issue);
        } finally {
            --$this->depth;
        }

        $this->entityManager->persist(new AutomationRun($rule, $issue, $trigger, $actor, RunOutcome::of($steps), $steps));
    }

    /**
     * @param RuleStep<TriggerType> $trigger
     */
    private function triggers(RuleStep $trigger, IssueEvent $event): bool
    {
        return match ($trigger->type) {
            TriggerType::IssueCreated => $event instanceof IssueCreated,
            TriggerType::CommentAdded => $event instanceof CommentAdded || ($event instanceof IssueTransitioned && null !== $event->comment),
            TriggerType::TransitionApplied => $event instanceof IssueTransitioned && $event->transitionId === $trigger->text('transition'),
            TriggerType::PlaceChanged => ($event instanceof IssueTransitioned || $event instanceof IssueMoved)
                && \in_array($trigger->text('place'), ['', $event->issue->getPlace()], true),
            TriggerType::CriticalityChanged => $event instanceof IssueUpdated
                && array_any($event->entries, static fn ($entry): bool => 'criticality' === $entry->text('field')),
            TriggerType::InactiveFor => false,
        };
    }

    /**
     * A rule kept in a shape this code no longer reads does nothing.
     */
    private function definitionOf(AutomationRule $rule): ?RuleDefinition
    {
        try {
            return $rule->getDefinition();
        } catch (AutomationException) {
            return null;
        }
    }
}
