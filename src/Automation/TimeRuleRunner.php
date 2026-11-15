<?php

declare(strict_types=1);

namespace App\Automation;

use App\Entity\AutomationRule;
use App\Message\Command\Automation\RunRule;
use App\Message\CommandBus;
use App\Repository\AutomationRuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Runs the rules that wait for time ("no activity for 30 days"), every
 * hour: for each of them, the open issues of its project nobody acted on
 * for that long, on which it has not run since. "Acted" is a person or a
 * forge (Issue::$lastActivityAt): what rules do does not count, so a
 * reminder written by one rule does not put off the closing another
 * promises. A rule acts once on an issue, then waits for someone to
 * touch it; an issue whose conditions do not hold is left alone and
 * looked at again the next hour.
 *
 * Each issue is its own RunRule command, hence its own transaction: one
 * that fails is logged and the others go on. The Scheduler calls
 * `runDue()`; `app:automations:run` does by hand.
 */
final class TimeRuleRunner
{
    private const int BATCH = 200;

    public function __construct(
        private readonly AutomationRuleRepository $rules,
        private readonly RuleConditions $conditions,
        private readonly CommandBus $commands,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param bool $dryRun count what is due without running anything
     *
     * @return int how many times a rule ran on an issue (or would have)
     */
    #[AsPeriodicTask('1 hour', jitter: 120)]
    public function runDue(bool $dryRun = false): int
    {
        $ran = 0;
        // Ids first: the unit of work is cleared between the batches of issues.
        foreach (array_map(static fn (AutomationRule $rule): int => (int) $rule->getId(), $this->rules->findAllActive()) as $ruleId) {
            $ran += $this->runRule($ruleId, $dryRun);
        }

        return $ran;
    }

    private function runRule(int $ruleId, bool $dryRun): int
    {
        $ran = 0;
        $afterId = 0;
        do {
            $rule = $this->rules->find($ruleId);
            try {
                $definition = $rule?->getDefinition();
            } catch (AutomationException) {
                return $ran;
            }
            $days = $definition?->inactivityDays();
            if (null === $rule || null === $definition || null === $days || $rule->getProject()->isReadOnly()) {
                return $ran;
            }

            $due = $this->rules->findDueIssues($rule, $this->clock->now()->modify(\sprintf('-%d days', $days)), $afterId, self::BATCH);
            $issueIds = [];
            foreach ($due as $issue) {
                $afterId = (int) $issue->getId();
                // What does not hold is not worth a transaction.
                if ($rule->appliesTo($issue->getTracker()) && $this->conditions->hold($definition, $issue, null)) {
                    $issueIds[] = $afterId;
                }
            }
            foreach ($issueIds as $issueId) {
                if ($dryRun) {
                    ++$ran;
                    continue;
                }
                try {
                    $ran += true === $this->commands->dispatch(new RunRule($ruleId, $issueId)) ? 1 : 0;
                } catch (\Throwable $e) {
                    $this->logger->error('An automation rule could not run on an issue.', ['rule' => $ruleId, 'issue' => $issueId, 'exception' => $e]);
                }
            }
            $this->entityManager->clear();
        } while (self::BATCH === \count($due));

        return $ran;
    }
}
