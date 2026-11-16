<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Template;

use App\Automation\AutomationException;
use App\Automation\Definition\RuleContext;
use App\Automation\RuleContexts;
use App\Message\Command\Template\UpdateTemplateRule;
use App\MessageHandler\Command\Automation\RuleWriting;
use App\Repository\TemplateRuleRepository;
use App\Repository\TemplateTrackerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Checks every change before applying any. As for the rule of a project,
 * a rule that only changes its name or is switched on or off is not
 * checked again.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class UpdateTemplateRuleHandler
{
    public function __construct(
        private readonly TemplateRuleRepository $rules,
        private readonly TemplateTrackerRepository $trackers,
        private readonly RuleContexts $contexts,
        private readonly RuleWriting $writing,
    ) {
    }

    public function __invoke(UpdateTemplateRule $command): void
    {
        $rule = $this->rules->find($command->ruleId) ?? throw TemplateException::notFound('Template rule', $command->ruleId);
        $template = $rule->getTemplate();

        $tracker = $rule->getTracker();
        if (null !== $command->trackerId) {
            $tracker = UpdateTemplateRule::NO_TRACKER === $command->trackerId ? null : $this->trackers->find($command->trackerId);
            if (UpdateTemplateRule::NO_TRACKER !== $command->trackerId && $tracker?->getTemplate() !== $template) {
                throw AutomationException::unknownTracker();
            }
        }
        $definition = $this->writing->rewritten($command->definition, null !== $command->trackerId, $rule->getDefinition(...), $tracker, fn (): RuleContext => $this->contexts->ofTemplate($template));

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
