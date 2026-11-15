<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Automation;

use App\Automation\AutomationException;
use App\Automation\Definition\RuleChecker;
use App\Automation\Definition\RuleDefinition;
use App\Automation\RuleContexts;
use App\Message\Command\Automation\UpdateRule;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\AutomationRuleRepository;
use App\Repository\TrackerRepository;
use App\Security\Permission;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Checks every change before applying any. A rule that only changes its
 * name or is switched on or off is not checked again: one that stopped
 * meaning something (a place gone from the configuration) can still be
 * deactivated.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class UpdateRuleHandler
{
    public function __construct(
        private readonly AutomationRuleRepository $rules,
        private readonly TrackerRepository $trackers,
        private readonly RuleContexts $contexts,
        private readonly RuleChecker $checker,
        private readonly ProjectActor $actor,
    ) {
    }

    public function __invoke(UpdateRule $command): void
    {
        $rule = $this->rules->find($command->ruleId) ?? throw AutomationException::notFound('Rule', $command->ruleId);
        $project = $rule->getProject();
        $this->actor->denyUnlessGranted($command->actorId, Permission::ProjectConfigure, $project);

        $redefined = null !== $command->trackerId || null !== $command->definition;
        $tracker = $rule->getTracker();
        $definition = null;
        if ($redefined) {
            if (null !== $command->trackerId) {
                $tracker = UpdateRule::NO_TRACKER === $command->trackerId ? null : $this->trackers->find($command->trackerId);
                if (UpdateRule::NO_TRACKER !== $command->trackerId && $tracker?->getProject() !== $project) {
                    throw AutomationException::unknownTracker();
                }
            }
            $definition = null !== $command->definition ? RuleDefinition::fromArray($command->definition) : $rule->getDefinition();
            $this->checker->check($definition, null === $tracker ? null : (string) $tracker->getId(), $this->contexts->ofProject($project));
        }

        if (null !== $command->name) {
            $rule->rename($command->name);
        }
        if (null !== $definition) {
            $rule->redefine($tracker, $definition);
        }
        if (null !== $command->active) {
            $command->active ? $rule->activate() : $rule->deactivate();
        }
    }
}
