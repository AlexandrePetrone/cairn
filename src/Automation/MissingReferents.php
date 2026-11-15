<?php

declare(strict_types=1);

namespace App\Automation;

use App\Automation\Definition\Target;
use App\Config\ConfigRegistry;
use App\Entity\AutomationRule;
use App\Entity\Project;
use App\Security\Referents;

/**
 * The roles the active rules of a project aim at through their referent
 * while the project has none that answers: nobody is designated, or who
 * is has a deactivated account or another role since. What those rules
 * say they will do is skipped until then, which the page of the rules
 * says, with the reason, before an execution does.
 */
final class MissingReferents
{
    public function __construct(
        private readonly Referents $referents,
        private readonly ConfigRegistry $config,
    ) {
    }

    /**
     * @param list<AutomationRule> $rules
     *
     * @return array<string, array{label: string, reason: string, name: string}> role id => its label, why it has no referent (`none`: nobody is designated; `deactivated`: the account of who is; `other_role`: who is holds another role on the project now) and the name of who is designated, '' for nobody
     */
    public function of(Project $project, array $rules): array
    {
        $present = $this->referents->all($project);
        $marked = $this->referents->marked($project);
        $definition = $this->config->definition();
        $missing = [];
        foreach ($rules as $rule) {
            if (!$rule->isActive()) {
                continue;
            }
            try {
                $actions = $rule->getDefinition()->actions;
            } catch (AutomationException) {
                continue;
            }
            foreach ($actions as $action) {
                $target = Target::parse($action->params['target'] ?? null);
                if (Target::REFERENT !== $target?->kind || isset($present[$target->argument])) {
                    continue;
                }
                $holder = $marked[$target->argument] ?? null;
                $missing[$target->argument] = [
                    'label' => $definition->hasRole($target->argument) ? $definition->role($target->argument)->label : $target->argument,
                    'reason' => match (true) {
                        null === $holder => 'none',
                        !$holder->isActive() => 'deactivated',
                        default => 'other_role',
                    },
                    'name' => (string) $holder?->getFullName(),
                ];
            }
        }

        return $missing;
    }
}
