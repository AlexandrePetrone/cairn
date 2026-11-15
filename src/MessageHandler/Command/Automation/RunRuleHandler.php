<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Automation;

use App\Automation\AutomationException;
use App\Automation\RuleEngine;
use App\Message\Command\Automation\RunRule;
use App\Repository\AutomationRuleRepository;
use App\Repository\IssueRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Asks again whether the rule is due on the issue, by the rule the
 * runner picked it with (AutomationRuleRepository::isDueOn()): between the
 * two someone may have commented, or another worker run the same rule.
 * What is no longer due ends quietly.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class RunRuleHandler
{
    public function __construct(
        private readonly AutomationRuleRepository $rules,
        private readonly IssueRepository $issues,
        private readonly RuleEngine $engine,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(RunRule $command): bool
    {
        $rule = $this->rules->find($command->ruleId);
        $issue = $this->issues->find($command->issueId);
        if (null === $rule || null === $issue || !$rule->isActive()) {
            return false;
        }
        try {
            $days = $rule->getDefinition()->inactivityDays();
        } catch (AutomationException) {
            return false;
        }
        if (null === $days || !$this->rules->isDueOn($rule, $this->clock->now()->modify(\sprintf('-%d days', $days)), $issue)) {
            return false;
        }

        return $this->engine->runTimed($rule, $issue);
    }
}
