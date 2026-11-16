<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Automation;

use App\Automation\Definition\RuleChecker;
use App\Automation\Definition\RuleContext;
use App\Automation\Definition\RuleDefinition;
use App\Entity\TemplateTracker;
use App\Entity\Tracker;

/**
 * What the commands that keep a rule share, whether the rule is one of a
 * project or of a project template: reading what was sent and checking
 * it where the rule is written. The handler finds the tracker and gives
 * the context; the rule it keeps is its own.
 */
final class RuleWriting
{
    public function __construct(
        private readonly RuleChecker $checker,
    ) {
    }

    /**
     * The rule as sent, checked against the tracker it is limited to.
     *
     * @param array<string, mixed> $definition
     */
    public function written(array $definition, Tracker|TemplateTracker|null $tracker, RuleContext $context): RuleDefinition
    {
        return $this->checked(RuleDefinition::fromArray($definition), $tracker, $context);
    }

    /**
     * What a change makes of a rule, checked, or null when it touches
     * neither what the rule says nor where it holds: a rule that only
     * changes its name or is switched on or off is not read again, so
     * one that stopped meaning something can still be deactivated.
     *
     * @param array<string, mixed>|null  $definition what the change says, null to keep what the rule says
     * @param bool                       $moved      whether the change names the tracker
     * @param \Closure(): RuleDefinition $current    what the rule says today
     * @param \Closure(): RuleContext    $context    where the rule is written
     */
    public function rewritten(?array $definition, bool $moved, \Closure $current, Tracker|TemplateTracker|null $tracker, \Closure $context): ?RuleDefinition
    {
        if (null === $definition && !$moved) {
            return null;
        }

        return $this->checked(null !== $definition ? RuleDefinition::fromArray($definition) : $current(), $tracker, $context());
    }

    private function checked(RuleDefinition $definition, Tracker|TemplateTracker|null $tracker, RuleContext $context): RuleDefinition
    {
        $this->checker->check($definition, null === $tracker ? null : (string) $tracker->getId(), $context);

        return $definition;
    }
}
