<?php

declare(strict_types=1);

namespace App\Twig;

use App\Automation\AutomationException;
use App\Automation\Definition\RuleContext;
use App\Automation\Definition\TriggerType;
use App\Automation\RuleContexts;
use App\Automation\RuleSentence;
use App\Entity\AutomationRule;
use App\Entity\TemplateRule;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * `rule_sentence(rule)`: an automation rule in one sentence, for the
 * list of the rules of a project or of a project template;
 * `rule_is_timed(rule)`: whether it waits for time instead of an event,
 * which is how the list is cut.
 */
final class RuleSentenceExtension implements ResetInterface
{
    /** @var array<string, RuleContext> the context of each project or template, read once per request */
    private array $contexts = [];

    public function __construct(
        private readonly RuleSentence $sentence,
        private readonly RuleContexts $ruleContexts,
    ) {
    }

    #[AsTwigFunction('rule_sentence')]
    public function sentence(AutomationRule|TemplateRule $rule): string
    {
        try {
            $definition = $rule->getDefinition();
        } catch (AutomationException) {
            return '…';
        }
        if ($rule instanceof TemplateRule) {
            $template = $rule->getTemplate();
            $context = $this->contexts['template:'.$template->getId()] ??= $this->ruleContexts->ofTemplate($template);
        } else {
            $project = $rule->getProject();
            $context = $this->contexts['project:'.$project->getId()] ??= $this->ruleContexts->ofProject($project);
        }

        return $this->sentence->of($definition, null === $rule->getTracker() ? null : (string) $rule->getTracker()->getId(), $context);
    }

    #[AsTwigFunction('rule_is_timed')]
    public function isTimed(AutomationRule|TemplateRule $rule): bool
    {
        try {
            return $rule->getDefinition()->trigger->type->isTimed();
        } catch (AutomationException) {
            return false;
        }
    }

    #[AsTwigFunction('rule_trigger_is_timed')]
    public function triggerIsTimed(string $trigger): bool
    {
        return true === TriggerType::tryFrom($trigger)?->isTimed();
    }

    public function reset(): void
    {
        $this->contexts = [];
    }
}
