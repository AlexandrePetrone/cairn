<?php

declare(strict_types=1);

namespace App\Entity\Enum;

use App\Automation\StepResult;

/**
 * How an execution of an automation rule ended.
 */
enum RunOutcome: string
{
    /** Every action was done, or had nothing to change. */
    case Applied = 'applied';
    /** Some actions were done, others could not be: the steps of the execution say which. */
    case Partial = 'partial';
    /** No action could be done: no referent, an account deactivated, a place gone. */
    case Skipped = 'skipped';
    /** The rule was not run: too many rules had already followed one another. */
    case Stopped = 'stopped';

    /**
     * How an execution ended, from what each of its actions did: skipped
     * when none could be done, partial when some could not, applied
     * otherwise (an action with nothing to change was done).
     *
     * @param list<StepResult> $steps
     */
    public static function of(array $steps): self
    {
        $skipped = \count(array_filter($steps, static fn (StepResult $step): bool => $step->isSkipped()));

        return match (true) {
            0 === $skipped => self::Applied,
            $skipped === \count($steps) => self::Skipped,
            default => self::Partial,
        };
    }
}
