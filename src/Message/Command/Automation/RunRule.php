<?php

declare(strict_types=1);

namespace App\Message\Command\Automation;

/**
 * Runs a rule that waits for time on one issue it is due on. Dispatched
 * by Automation\TimeRuleRunner, one command per issue, so each is its
 * own transaction; no actor: time acts. Handled by RunRuleHandler, which
 * checks again that the rule is still due there and answers whether it
 * ran.
 */
final readonly class RunRule
{
    public function __construct(
        public int $ruleId,
        public int $issueId,
    ) {
    }
}
