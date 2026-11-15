<?php

declare(strict_types=1);

namespace App\Automation\Definition;

/**
 * What must hold for a triggered rule to act; every condition of a rule
 * must. A closed list: a new case comes with its test in RuleConditions
 * and its labels (`automation.condition.<value>`).
 */
enum ConditionType: string
{
    /** Who did what triggered the rule: an internal user, or an external one. */
    case Actor = 'actor';
    case Assignee = 'assignee';
    /** The issue is on one of the places listed. */
    case Place = 'place';
    /** The issue has one of the criticalities listed. */
    case Criticality = 'criticality';
    /** Whether a commit or a merge request references the issue. */
    case Code = 'code';

    public const string INTERNAL = 'internal';
    public const string EXTERNAL = 'external';
    public const string NOBODY = 'nobody';
    public const string EXTERNAL_OR_NOBODY = 'external_or_nobody';
    public const string NONE = 'none';
    public const string SOME = 'some';

    /**
     * @return list<Parameter>
     */
    public function parameters(): array
    {
        return match ($this) {
            self::Actor => [new Parameter('is', ParameterKind::Choice, [self::INTERNAL, self::EXTERNAL])],
            self::Assignee => [new Parameter('is', ParameterKind::Choice, [self::INTERNAL, self::EXTERNAL, self::NOBODY, self::EXTERNAL_OR_NOBODY])],
            self::Place => [new Parameter('is', ParameterKind::Place, multiple: true)],
            self::Criticality => [new Parameter('is', ParameterKind::Criticality, multiple: true)],
            self::Code => [new Parameter('is', ParameterKind::Choice, [self::NONE, self::SOME])],
        };
    }
}
