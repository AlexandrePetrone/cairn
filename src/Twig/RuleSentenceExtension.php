<?php

declare(strict_types=1);

namespace App\Twig;

use App\Automation\AutomationException;
use App\Automation\Definition\RuleContext;
use App\Automation\Definition\TriggerType;
use App\Automation\RuleContexts;
use App\Automation\RuleSentence;
use App\Entity\AutomationRule;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * `rule_sentence(rule)`: an automation rule in one sentence, for the
 * list of the rules of a project; `rule_is_timed(rule)`: whether it
 * waits for time instead of an event, which is how the list is cut.
 */
final class RuleSentenceExtension implements ResetInterface
{
    /** @var array<int, RuleContext> the context of each project, read once per request */
    private array $contexts = [];

    public function __construct(
        private readonly RuleSentence $sentence,
        private readonly RuleContexts $ruleContexts,
    ) {
    }

    #[AsTwigFunction('rule_sentence')]
    public function sentence(AutomationRule $rule): string
    {
        try {
            $definition = $rule->getDefinition();
        } catch (AutomationException) {
            return '…';
        }
        $project = $rule->getProject();
        $context = $this->contexts[(int) $project->getId()] ??= $this->ruleContexts->ofProject($project);

        return $this->sentence->of($definition, null === $rule->getTracker() ? null : (string) $rule->getTracker()->getId(), $context);
    }

    #[AsTwigFunction('rule_is_timed')]
    public function isTimed(AutomationRule $rule): bool
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
