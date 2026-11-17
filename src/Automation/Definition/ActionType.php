<?php

declare(strict_types=1);

namespace App\Automation\Definition;

use App\Entity\Enum\BudgetLineStatus;

/**
 * What a rule does to the issue, in the order written. A closed list: a
 * new case comes with its effect in RuleExecutor and its labels
 * (`automation.action.<value>`).
 */
enum ActionType: string
{
    case Assign = 'assign';
    /** Puts the issue on a place of its workflow, whether an action leads there or not. */
    case Move = 'move';
    case SetCriticality = 'set_criticality';
    /** Makes someone follow the issue. */
    case Subscribe = 'subscribe';
    case Comment = 'comment';
    case MakePrivate = 'make_private';
    /**
     * Marks the budget lines that name the issue and stand in one of the
     * statuses `from`, as `status`: the one thing a rule does that is not
     * a change of the issue.
     */
    case MarkBudgetLines = 'mark_budget_lines';

    /**
     * @return list<Parameter>
     */
    public function parameters(): array
    {
        return match ($this) {
            self::Assign, self::Subscribe => [new Parameter('target', ParameterKind::Target)],
            self::Move => [new Parameter('place', ParameterKind::ScopedPlace)],
            self::SetCriticality => [new Parameter('criticality', ParameterKind::Criticality)],
            self::Comment => [new Parameter('body', ParameterKind::Text), new Parameter('private', ParameterKind::Flag, required: false)],
            self::MakePrivate => [],
            self::MarkBudgetLines => [
                new Parameter('status', ParameterKind::Choice, array_column(BudgetLineStatus::cases(), 'value')),
                new Parameter('from', ParameterKind::Choice, array_column(BudgetLineStatus::cases(), 'value'), multiple: true),
            ],
        };
    }
}
