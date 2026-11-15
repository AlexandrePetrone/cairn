<?php

declare(strict_types=1);

namespace App\Automation\Definition;

/**
 * What starts a rule: something that happened to an issue (a domain
 * event, matched by RuleEngine inside the command's transaction), or
 * nothing happening to it for a while (TimeRuleRunner). A closed list:
 * a new case comes with its match in RuleEngine and its labels
 * (`automation.trigger.<value>`).
 */
enum TriggerType: string
{
    case IssueCreated = 'issue_created';
    /** A comment was written, alone or with an action. */
    case CommentAdded = 'comment_added';
    /** A given action of the workflow was applied. */
    case TransitionApplied = 'transition_applied';
    /** The issue took another place, by an action or not; a given one, or any. */
    case PlaceChanged = 'place_changed';
    case CriticalityChanged = 'criticality_changed';
    /** Nobody acted on the issue for a number of days. */
    case InactiveFor = 'inactive_for';

    /**
     * @return list<Parameter>
     */
    public function parameters(): array
    {
        return match ($this) {
            self::TransitionApplied => [new Parameter('transition', ParameterKind::Transition)],
            self::PlaceChanged => [new Parameter('place', ParameterKind::Place, required: false)],
            self::InactiveFor => [new Parameter('days', ParameterKind::Days)],
            default => [],
        };
    }

    /**
     * Whether the rule waits for time to pass instead of an event: no
     * person acts then, so nothing can be asked about one.
     */
    public function isTimed(): bool
    {
        return self::InactiveFor === $this;
    }
}
