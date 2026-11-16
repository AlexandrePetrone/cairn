<?php

declare(strict_types=1);

namespace App\Automation\Definition;

/**
 * The kinds of value a trigger, a condition or an action of a rule may
 * ask for. A kind says how the value is checked (RuleChecker) and which
 * field edits it (Form\Automation\RuleType): a new type of step picks
 * among them, it never brings its own form.
 */
enum ParameterKind
{
    /** One of the `options` of the parameter. */
    case Choice;
    /** A number of days, from 1. */
    case Days;
    /** A place the rule may meet, by its id: one of the workflow of the tracker it is limited to, or of any tracker without one. */
    case Place;
    /** A place of the workflow of the tracker the rule is limited to, by its id. */
    case ScopedPlace;
    /** An action of the workflow of the tracker the rule is limited to, by its id. */
    case Transition;
    /** A Criticality value. */
    case Criticality;
    /** Someone, named without naming a person where possible (Target). */
    case Target;
    /** Markdown written by whoever configures the rule. */
    case Text;
    /** Yes or no. */
    case Flag;

    /**
     * Whether the value is picked among what is offered where the rule is
     * written (a place, a member, an option), not typed: one that is no
     * longer offered cannot stay in a form.
     */
    public function isChosen(): bool
    {
        return !\in_array($this, [self::Days, self::Text, self::Flag], true);
    }

    /**
     * Whether the value only means something within one tracker: the rule
     * must then be limited to it.
     */
    public function needsTracker(): bool
    {
        return self::ScopedPlace === $this || self::Transition === $this;
    }
}
